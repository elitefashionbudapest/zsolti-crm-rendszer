<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ExtractionRepository;
use App\Auth\Auth;
use App\Clients\ClientRepository;
use App\Contracts\ContractRepository;
use App\Documents\DocumentStorage;
use App\Settings\SettingsService;
use App\Support\AuditLogger;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Slim\Views\Twig;

/**
 * AI adatkinyerés: dokumentum (PDF/kép) feltöltése, a Claude-dal kinyert adatok
 * ellenőrzése és jóváhagyása, majd partner és szerződés létrehozása — tenant-tudatosan.
 */
final class AiExtractionController
{
    private const MAX_SIZE = 20 * 1024 * 1024;
    private const ALLOWED_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

    /** A felülvizsgálati űrlapon szerkeszthető mezők. */
    private const FIELDS = [
        'client_name', 'client_email', 'client_phone', 'client_address',
        'tax_id', 'birth_date', 'birth_place', 'mother_name',
        'insurer_name', 'module_name', 'policy_number', 'offer_number',
        'start_date', 'end_date', 'annual_fee', 'plate',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private ExtractionRepository $extractions,
        private SettingsService $settings,
        private ClientRepository $clients,
        private ContractRepository $contracts,
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
        $model = (string) $this->settings->get($officeId, 'anthropic_model', 'claude-opus-4-8');

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

        $values = [];
        foreach (self::FIELDS as $f) {
            $values[$f] = (string) ($fields[$f] ?? '');
        }

        return $this->twig->render($response, 'admin/ai/review.twig', [
            'active' => 'documents',
            'extraction' => $row,
            'fields' => $values,
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

        $data = $this->extractFields($request);

        // A szerkesztett mezők visszamentése a rekordba.
        $this->extractions->setFields($id, (string) json_encode($data, JSON_UNESCAPED_UNICODE));

        $clientData = [
            'name' => $data['client_name'] ?? null,
            'email' => $data['client_email'] ?? null,
            'phone' => $data['client_phone'] ?? null,
            'address' => $data['client_address'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'birth_place' => $data['birth_place'] ?? null,
            'mother_name' => $data['mother_name'] ?? null,
            'status' => 'active',
            'owner_user_id' => $this->auth->id(),
        ];
        if ($this->isDate($data['birth_date'] ?? null)) {
            $clientData['birth_date'] = $data['birth_date'];
        }

        $clientId = $this->clients->create($clientData);

        // Ha bármilyen szerződés-mező van, hozzunk létre szerződést is.
        if (($data['insurer_name'] ?? null) !== null
            || ($data['module_name'] ?? null) !== null
            || ($data['policy_number'] ?? null) !== null
        ) {
            $this->contracts->create([
                'client_id' => $clientId,
                'insurer_name' => $data['insurer_name'] ?? null,
                'module_name' => $data['module_name'] ?? null,
                'policy_number' => $data['policy_number'] ?? null,
                'offer_number' => $data['offer_number'] ?? null,
                'start_date' => $this->isDate($data['start_date'] ?? null) ? $data['start_date'] : null,
                'end_date' => $this->isDate($data['end_date'] ?? null) ? $data['end_date'] : null,
                'annual_fee' => $data['annual_fee'] ?? null,
                'plate' => $data['plate'] ?? null,
                'status' => 'active',
            ]);
        }

        $this->extractions->updateStatus($id, 'approved');
        $this->extractions->attachClient($id, $clientId);
        $this->audit->log('ai.extract.approve', 'extracted_data', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'A partner a kinyert adatokból létrejött.'];

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

    /** @return array<string,?string> */
    private function extractFields(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        $data = [];
        foreach (self::FIELDS as $f) {
            $val = trim((string) ($body[$f] ?? ''));
            $data[$f] = $val === '' ? null : $val;
        }

        return $data;
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
