<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ExtractionRepository;
use App\Auth\Auth;
use App\Clients\ClientAttributeRepository;
use App\Clients\ClientRepository;
use App\Contracts\ContractRepository;
use App\Documents\DocumentStorage;
use App\Settings\SettingsService;
use App\Support\AuditLogger;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * AI adatkinyerés: dokumentum (PDF/kép) feltöltése, a Claude-dal kinyert adatok
 * ellenőrzése és jóváhagyása, majd partner és szerződés létrehozása — tenant-tudatosan.
 * A nevesített mezőkön túli adatokat címezhető kulcs-érték attribútumként tároljuk.
 */
final class AiExtractionController
{
    private const MAX_SIZE = 20 * 1024 * 1024;
    private const ALLOWED_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

    /** A felülvizsgálati űrlapon szerkeszthető, nevesített mezők (DB-oszlopokra képezve). */
    private const FIELDS = [
        'client_name', 'client_email', 'client_phone', 'client_mobile', 'client_address',
        'tax_id', 'birth_date', 'birth_place', 'mother_name',
        'category', 'insurer_name', 'module_code', 'module_name', 'policy_number', 'offer_number',
        'start_date', 'end_date', 'anniversary', 'annual_fee', 'payment_frequency', 'payment_method',
        'agent_code', 'agent_name', 'risk_location', 'plate',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private ExtractionRepository $extractions,
        private SettingsService $settings,
        private ClientRepository $clients,
        private ContractRepository $contracts,
        private ClientAttributeRepository $attributes,
        private AuditLogger $audit,
        private DocumentStorage $storage,
        private PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/ai/index.twig', [
            'active' => 'documents',
            'pending' => $this->extractions->pending(),
            'flash' => $this->flash(),
        ]);
    }

    public function upload(Request $request, Response $response): Response
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        $error = $this->validateFile($file);
        if ($error !== null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => $error];

            return $this->redirect($response, '/admin/ai-kinyeres');
        }

        /** @var UploadedFileInterface $file */
        $officeId = (int) $this->auth->officeId();

        $apiKey = (string) $this->settings->get($officeId, 'anthropic_api_key', '');
        if ($apiKey === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Állítsd be az Anthropic API kulcsot a Beállításoknál.'];

            return $this->redirect($response, '/admin/ai-kinyeres');
        }
        // A kinyeréshez a leggyorsabb és legolcsóbb modellt használjuk (a mezők
        // kiolvasásához bőven elég), hogy egy nagy dokumentum se fogyasszon sok kreditet.
        $model = 'claude-haiku-4-5';

        $binary = (string) $file->getStream()->getContents();
        $mime = (string) ($file->getClientMediaType() ?? '');
        $meta = $this->storage->saveBytes($officeId, $binary, (string) ($file->getClientFilename() ?? 'dokumentum'), $mime);

        // A tényleges AI-hívást háttérben (worker/cron) végezzük — a web-kérés
        // a szerver request-timeoutja miatt nem tudja megvárni a hosszú feldolgozást.
        $id = $this->extractions->create([
            'document_id' => null,
            'client_id' => null,
            'fields' => '{}',
            'status' => 'processing',
            'model' => $model,
            'created_by' => $this->auth->id(),
        ]);

        $now = date('Y-m-d H:i:s');
        $payload = json_encode([
            'extraction_id' => $id,
            'office_id' => $officeId,
            'stored_path' => $meta['stored_path'],
            'mime' => $mime,
            'model' => $model,
        ], JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare(
            'INSERT INTO jobs (queue, type, payload, attempts, available_at, created_at, updated_at)
             VALUES (:q, :t, :p, 0, :a, :c, :u)'
        )->execute(['q' => 'default', 't' => 'ai_extract', 'p' => $payload, 'a' => $now, 'c' => $now, 'u' => $now]);

        $this->audit->log('ai.extract.enqueue', 'extracted_data', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'A dokumentum feldolgozás alatt van — az adatok hamarosan megjelennek.'];

        return $this->redirect($response, '/admin/ai-kinyeres/' . $id);
    }

    public function review(Request $request, Response $response, array $args): Response
    {
        $row = $this->extractions->find((int) $args['id']);
        if ($row === null) {
            return $response->withStatus(404);
        }

        $decoded = json_decode((string) ($row['fields'] ?? ''), true);
        $fields = is_array($decoded) ? $decoded : [];

        return $this->twig->render($response, 'admin/ai/review.twig', [
            'active' => 'documents',
            'extraction' => $row,
            'fields' => $this->coreValues($fields),
            'extra' => $this->extraValues($fields),
            'duplicate' => null,
            'errorMsg' => is_array($decoded) ? ($decoded['_error'] ?? null) : null,
            'flash' => $this->flash(),
        ]);
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $row = $this->extractions->find($id);
        if ($row === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $data = $this->extractFields($body);
        $extra = $this->extractExtra($body);

        // A szerkesztett mezők (nevesített + attribútumok) visszamentése a rekordba.
        $this->extractions->setFields($id, (string) json_encode(
            $data + ['additional_fields' => $extra],
            JSON_UNESCAPED_UNICODE
        ));

        // Meglévő partner keresése (adóazonosító, majd e-mail alapján). Ha van és a
        // felhasználó még nem erősítette meg a felülírást, visszakérdezünk.
        $existing = $this->clients->findDuplicate($data['tax_id'] ?? null, $data['client_email'] ?? null);
        $confirmOverwrite = (string) ($body['overwrite'] ?? '') === '1';

        if ($existing !== null && !$confirmOverwrite) {
            return $this->twig->render($response, 'admin/ai/review.twig', [
                'active' => 'documents',
                'extraction' => $row,
                'fields' => $data,
                'extra' => $extra,
                'duplicate' => ['id' => (int) $existing['id'], 'name' => (string) $existing['name']],
                'errorMsg' => null,
                'flash' => null,
            ]);
        }

        $clientData = [
            'name' => $data['client_name'] ?? null,
            'email' => $data['client_email'] ?? null,
            'phone' => $data['client_phone'] ?? null,
            'mobile' => $data['client_mobile'] ?? null,
            'address' => $data['client_address'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'birth_place' => $data['birth_place'] ?? null,
            'mother_name' => $data['mother_name'] ?? null,
            'status' => 'active',
        ];
        if ($this->isDate($data['birth_date'] ?? null)) {
            $clientData['birth_date'] = $data['birth_date'];
        }

        if ($existing !== null) {
            // Felülírás megerősítve: a meglévő partner adatai frissülnek (csak a
            // kitöltött mezők; az üreseket nem írjuk felül).
            $clientId = (int) $existing['id'];
            $this->clients->update($clientId, array_filter(
                $clientData,
                static fn ($v): bool => $v !== null && $v !== ''
            ));
        } else {
            $clientData['owner_user_id'] = $this->auth->id();
            $clientId = $this->clients->create($clientData);
        }

        // Ha bármilyen szerződés-mező van, hozzunk létre szerződést is.
        if (($data['insurer_name'] ?? null) !== null
            || ($data['module_name'] ?? null) !== null
            || ($data['policy_number'] ?? null) !== null
        ) {
            $this->contracts->create([
                'client_id' => $clientId,
                'category' => $data['category'] ?? null,
                'insurer_name' => $data['insurer_name'] ?? null,
                'module_code' => $data['module_code'] ?? null,
                'module_name' => $data['module_name'] ?? null,
                'policy_number' => $data['policy_number'] ?? null,
                'offer_number' => $data['offer_number'] ?? null,
                'start_date' => $this->isDate($data['start_date'] ?? null) ? $data['start_date'] : null,
                'end_date' => $this->isDate($data['end_date'] ?? null) ? $data['end_date'] : null,
                'anniversary' => $data['anniversary'] ?? null,
                'annual_fee' => $data['annual_fee'] ?? null,
                'payment_frequency' => $data['payment_frequency'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'agent_code' => $data['agent_code'] ?? null,
                'agent_name' => $data['agent_name'] ?? null,
                'risk_location' => $data['risk_location'] ?? null,
                'plate' => $data['plate'] ?? null,
                'status' => 'active',
            ]);
        }

        // A címezhető attribútumok mentése (felülírás = a partner régi attribútumai
        // törlődnek, és a most jóváhagyottak kerülnek be).
        $this->attributes->replaceForClient($clientId, $extra, $id);

        $this->extractions->updateStatus($id, 'approved');
        $this->extractions->attachClient($id, $clientId);
        $this->audit->log('ai.extract.approve', 'extracted_data', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $existing !== null
            ? 'A meglévő partner adatai felülírva a kinyert adatokból.'
            : 'A partner a kinyert adatokból létrejött.'];

        return $this->redirect($response, '/admin/partnerek/' . $clientId);
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->extractions->find($id) !== null) {
            $this->extractions->updateStatus($id, 'rejected');
            $this->audit->log('ai.extract.reject', 'extracted_data', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'A kinyert adatokat elvetetted.'];
        }

        return $this->redirect($response, '/admin/ai-kinyeres');
    }

    /**
     * A nevesített mezők értékei a review-űrlaphoz (minden mező kulcsa jelen van).
     *
     * @param array<string,mixed> $fields
     * @return array<string,string>
     */
    private function coreValues(array $fields): array
    {
        $values = [];
        foreach (self::FIELDS as $f) {
            $values[$f] = (string) ($fields[$f] ?? '');
        }

        return $values;
    }

    /**
     * Az additional_fields normalizálása a review-hoz (érvényes sorok, string értékek).
     *
     * @param array<string,mixed> $fields
     * @return array<int,array{group:string,attr_key:string,label:string,value:string}>
     */
    private function extraValues(array $fields): array
    {
        $out = [];
        foreach ((array) ($fields['additional_fields'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['attr_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $out[] = [
                'group' => (string) ($item['group'] ?? 'egyeb'),
                'attr_key' => $key,
                'label' => (string) ($item['label'] ?? $key),
                'value' => (string) ($item['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * A nevesített mezők beolvasása a POST-ból.
     *
     * @param array<string,mixed> $body
     * @return array<string,?string>
     */
    private function extractFields(array $body): array
    {
        $data = [];
        foreach (self::FIELDS as $f) {
            $val = trim((string) ($body[$f] ?? ''));
            $data[$f] = $val === '' ? null : $val;
        }

        return $data;
    }

    /**
     * Az attribútum-sorok beolvasása a POST-ból (extra[i][attr_key|label|value|group]).
     * Az üres kulcsú vagy üres értékű sorokat kihagyjuk.
     *
     * @param array<string,mixed> $body
     * @return array<int,array{group:string,attr_key:string,label:string,value:string}>
     */
    private function extractExtra(array $body): array
    {
        $rows = (array) ($body['extra'] ?? []);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['attr_key'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($key === '' || $value === '') {
                continue;
            }
            $out[] = [
                'group' => trim((string) ($row['group'] ?? 'egyeb')) ?: 'egyeb',
                'attr_key' => $key,
                'label' => trim((string) ($row['label'] ?? '')) ?: $key,
                'value' => $value,
            ];
        }

        return $out;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function validateFile(?UploadedFileInterface $file): ?string
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return 'Válassz ki egy fájlt a feltöltéshez.';
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'A fájl feltöltése sikertelen volt.';
        }
        if ((int) $file->getSize() > self::MAX_SIZE) {
            return 'A fájl mérete legfeljebb 20 MB lehet.';
        }
        $ext = strtolower(pathinfo((string) ($file->getClientFilename() ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return 'Nem engedélyezett fájltípus. Engedélyezett: ' . implode(', ', self::ALLOWED_EXT) . '.';
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
