<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Clients\ClientRepository;
use App\Contracts\ContractRepository;
use App\Documents\DocumentStorage;
use App\Support\AuditLogger;
use App\Templates\TemplateFiller;
use App\Templates\TemplateRepository;
use PDO;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Dokumentumsablonok kezelése és kitöltése (PDF rátét + DOCX sablon) —
 * tenant-tudatosan (office_id).
 */
final class TemplatesController
{
    private const MAX_SIZE = 20 * 1024 * 1024;

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private TemplateRepository $templates,
        private DocumentStorage $storage,
        private ClientRepository $clients,
        private ContractRepository $contracts,
        private AuditLogger $audit,
        private PDO $pdo,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/templates/index.twig', [
            'active' => 'documents',
            'templates' => $this->templates->listAll(),
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/templates/form.twig', [
            'active' => 'documents',
            'mode' => 'create',
            'action' => '/admin/sablonok',
            'template' => ['name' => '', 'kind' => 'overlay', 'field_map' => '', 'is_active' => 1],
            'errors' => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $kind = (string) ($body['kind'] ?? 'overlay');
        $fieldMapRaw = (string) ($body['field_map'] ?? '');
        $isActive = isset($body['is_active']) ? 1 : 0;
        $file = $request->getUploadedFiles()['file'] ?? null;

        $errors = $this->validate($name, $kind, $fieldMapRaw, $file, true);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/templates/form.twig', [
                'active' => 'documents',
                'mode' => 'create',
                'action' => '/admin/sablonok',
                'template' => ['name' => $name, 'kind' => $kind, 'field_map' => $fieldMapRaw, 'is_active' => $isActive],
                'errors' => $errors,
            ]);
        }

        /** @var UploadedFileInterface $file */
        try {
            $stored = $this->storage->save((int) $this->auth->officeId(), $file);
        } catch (Throwable) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A sablonfájl mentése nem sikerült.'];

            return $this->redirect($response, '/admin/sablonok/uj');
        }

        $id = $this->templates->create([
            'name' => $name,
            'kind' => $kind,
            'stored_path' => $stored['stored_path'],
            'field_map' => $fieldMapRaw,
            'is_active' => $isActive,
        ]);
        $this->audit->log('template.create', 'template', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Sablon létrehozva.'];

        return $this->redirect($response, '/admin/sablonok');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tpl = $this->templates->find((int) $args['id']);
        if ($tpl === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/templates/form.twig', [
            'active' => 'documents',
            'mode' => 'edit',
            'action' => '/admin/sablonok/' . $tpl['id'],
            'template' => $tpl,
            'errors' => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $tpl = $this->templates->find($id);
        if ($tpl === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $kind = (string) ($body['kind'] ?? 'overlay');
        $fieldMapRaw = (string) ($body['field_map'] ?? '');
        $isActive = isset($body['is_active']) ? 1 : 0;
        $file = $request->getUploadedFiles()['file'] ?? null;

        // Szerkesztésnél a fájl cseréje opcionális.
        $errors = $this->validate($name, $kind, $fieldMapRaw, $file, false);
        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'admin/templates/form.twig', [
                'active' => 'documents',
                'mode' => 'edit',
                'action' => '/admin/sablonok/' . $id,
                'template' => array_merge($tpl, ['name' => $name, 'kind' => $kind, 'field_map' => $fieldMapRaw, 'is_active' => $isActive]),
                'errors' => $errors,
            ]);
        }

        $data = [
            'name' => $name,
            'kind' => $kind,
            'field_map' => $fieldMapRaw,
            'is_active' => $isActive,
        ];

        // Ha új fájlt töltöttek fel, lecseréljük és a régit töröljük.
        if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
            try {
                $stored = $this->storage->save((int) $this->auth->officeId(), $file);
                $this->storage->delete((string) $tpl['stored_path']);
                $data['stored_path'] = $stored['stored_path'];
            } catch (Throwable) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Az új sablonfájl mentése nem sikerült.'];

                return $this->redirect($response, '/admin/sablonok/' . $id . '/szerkesztes');
            }
        }

        $this->templates->update($id, $data);
        $this->audit->log('template.update', 'template', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Sablon frissítve.'];

        return $this->redirect($response, '/admin/sablonok');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $tpl = $this->templates->find($id);
        if ($tpl !== null) {
            $this->storage->delete((string) $tpl['stored_path']);
            $this->templates->delete($id);
            $this->audit->log('template.delete', 'template', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Sablon törölve.'];
        }

        return $this->redirect($response, '/admin/sablonok');
    }

    public function fillForm(Request $request, Response $response, array $args): Response
    {
        $tpl = $this->templates->find((int) $args['id']);
        if ($tpl === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/templates/fill.twig', [
            'active' => 'documents',
            'template' => $tpl,
            'clients' => $this->contracts->clientsForOffice(),
            'contracts' => $this->contractsForOffice(),
            'flash' => $this->flash(),
        ]);
    }

    public function fill(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $tpl = $this->templates->find($id);
        if ($tpl === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $clientId = isset($body['client_id']) && $body['client_id'] !== '' ? (int) $body['client_id'] : null;
        $contractId = isset($body['contract_id']) && $body['contract_id'] !== '' ? (int) $body['contract_id'] : null;

        $client = $clientId !== null ? $this->clients->find($clientId) : null;
        if ($client === null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Válassz egy ügyfelet a kitöltéshez.'];

            return $this->redirect($response, '/admin/sablonok/' . $id . '/kitoltes');
        }

        $contract = null;
        if ($contractId !== null) {
            $contract = $this->contracts->find($contractId);
            // IDOR-védelem: a szerződés az adott ügyfélhez tartozzon.
            if ($contract !== null && (int) ($contract['client_id'] ?? 0) !== $clientId) {
                $contract = null;
            }
        }

        $data = $this->resolveData($client, $contract);

        $kind = (string) $tpl['kind'];
        $isDocx = $kind === 'docx';
        $ext = $isDocx ? 'docx' : 'pdf';
        $mime = $isDocx
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';

        $map = $this->decodeMap((string) ($tpl['field_map'] ?? ''));
        $sourcePath = $this->storage->fullPath((string) $tpl['stored_path']);

        $tmp = sys_get_temp_dir() . '/tplfill_' . bin2hex(random_bytes(8)) . '.' . $ext;

        $filler = new TemplateFiller();
        try {
            if ($isDocx) {
                /** @var array<string,string> $map */
                $filler->fillDocx($sourcePath, $map, $data, $tmp);
            } else {
                /** @var array<int,array<string,mixed>> $map */
                $filler->fillOverlay($sourcePath, $map, $data, $tmp);
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A sablon kitöltése nem sikerült: ' . $e->getMessage()];

            return $this->redirect($response, '/admin/sablonok/' . $id . '/kitoltes');
        }

        $safeName = $this->slug((string) $tpl['name']);
        $originalName = $safeName . '_' . date('Ymd_His') . '.' . $ext;

        // A generált fájlt eltesszük a feltöltési területre (irodánkénti almappa),
        // hogy a generated_documents sor egy valós fájlra mutasson.
        $storedPath = $this->persistGenerated($tmp, (int) $this->auth->officeId(), $ext);

        try {
            $this->insertGenerated($id, $clientId, $contractId, $storedPath ?? basename($tmp), $originalName);
        } catch (Throwable) {
            // A naplózó-sor hibája ne akadályozza a letöltést.
        }

        $this->audit->log('template.fill', 'template', $id);

        // A kitöltött fájl streamelése letöltésként a temp fájlból.
        $stream = (new StreamFactory())->createStreamFromFile($tmp);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $originalName . '"')
            ->withBody($stream);
    }

    /**
     * A kitöltési adatkulcsok feloldása a választott ügyfélből (+ szerződésből).
     *
     * @param array<string,mixed> $client
     * @param array<string,mixed>|null $contract
     * @return array<string,string>
     */
    private function resolveData(array $client, ?array $contract): array
    {
        $contract ??= [];

        return [
            'client_name' => (string) ($client['name'] ?? ''),
            'client_email' => (string) ($client['email'] ?? ''),
            'client_phone' => (string) ($client['mobile'] ?? $client['phone'] ?? ''),
            'client_address' => (string) ($client['address'] ?? ''),
            'tax_id' => (string) ($client['tax_id'] ?? ''),
            'birth_date' => (string) ($client['birth_date'] ?? ''),
            'birth_place' => (string) ($client['birth_place'] ?? ''),
            'mother_name' => (string) ($client['mother_name'] ?? ''),
            'policy_number' => (string) ($contract['policy_number'] ?? ''),
            'insurer_name' => (string) ($contract['insurer_name'] ?? ''),
            'module_name' => (string) ($contract['module_name'] ?? ''),
            'start_date' => (string) ($contract['start_date'] ?? ''),
            'end_date' => (string) ($contract['end_date'] ?? ''),
            'annual_fee' => (string) ($contract['annual_fee'] ?? ''),
            'plate' => (string) ($contract['plate'] ?? ''),
            'today' => date('Y-m-d'),
        ];
    }

    /**
     * A field_map JSON dekódolása. Hibás JSON esetén üres tömböt ad vissza,
     * így a kitöltés nem fatal.
     *
     * @return array<int|string,mixed>
     */
    private function decodeMap(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A temp fájl másolása a feltöltési területre, hogy a generated_documents
     * sor valós fájlra mutasson. Hiba esetén null (a letöltés ettől még működik).
     */
    private function persistGenerated(string $tmpPath, int $officeId, string $ext): ?string
    {
        try {
            $base = $this->storage->fullPath('');
        } catch (Throwable) {
            // A storage gyökerét nem tudjuk feloldani üres útvonalra: kiszámoljuk
            // egy meglévő office mappán keresztül nem lehet — visszalépünk.
            return null;
        }

        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'office_' . $officeId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $name = 'generated_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $name;

        $bytes = @file_get_contents($tmpPath);
        if ($bytes === false || @file_put_contents($dest, $bytes) === false) {
            return null;
        }

        return 'office_' . $officeId . '/' . $name;
    }

    /**
     * generated_documents sor beszúrása (a kitöltés naplózásához).
     */
    private function insertGenerated(int $templateId, ?int $clientId, ?int $contractId, string $storedPath, string $originalName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO generated_documents
                (office_id, template_id, client_id, contract_id, stored_path, original_name, created_at, updated_at)
             VALUES (:o, :t, :c, :ct, :sp, :on, :ts, :ts)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'o' => $this->auth->officeId(),
            't' => $templateId,
            'c' => $clientId,
            'ct' => $contractId,
            'sp' => $storedPath,
            'on' => $originalName,
            'ts' => $now,
        ]);
    }

    /**
     * Az aktuális iroda szerződései a kitöltő-űrlap legördülőjéhez
     * (ügyfél-azonosítóval, hogy kliensoldalon szűrhetők legyenek).
     *
     * @return array<int,array{id:int,client_id:int,label:string}>
     */
    private function contractsForOffice(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, client_id, policy_number, module_name, insurer_name
             FROM contracts WHERE office_id = :o ORDER BY created_at DESC'
        );
        $stmt->execute(['o' => $this->auth->officeId()]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $label = trim((string) ($row['policy_number'] ?? ''));
            $extra = trim((string) ($row['module_name'] ?? $row['insurer_name'] ?? ''));
            if ($extra !== '') {
                $label = $label !== '' ? $label . ' — ' . $extra : $extra;
            }
            if ($label === '') {
                $label = 'Szerződés #' . $row['id'];
            }
            $out[] = [
                'id' => (int) $row['id'],
                'client_id' => (int) $row['client_id'],
                'label' => $label,
            ];
        }

        return $out;
    }

    /**
     * @param string $name
     * @param string $kind
     * @param string $fieldMapRaw
     * @param UploadedFileInterface|null $file
     * @param bool $fileRequired
     * @return array<string,string>
     */
    private function validate(string $name, string $kind, string $fieldMapRaw, ?UploadedFileInterface $file, bool $fileRequired): array
    {
        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'] = 'A sablon neve kötelező (legalább 2 karakter).';
        }
        if (!in_array($kind, ['overlay', 'docx'], true)) {
            $errors['kind'] = 'Érvénytelen sablontípus.';
        }

        // A field_map JSON kell legyen (üresen megengedett).
        if (trim($fieldMapRaw) !== '' && json_decode($fieldMapRaw) === null) {
            $errors['field_map'] = 'A mezőtérkép érvénytelen JSON.';
        }

        $hasFile = $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE;
        if ($fileRequired && !$hasFile) {
            $errors['file'] = 'Tölts fel egy sablonfájlt.';
        }
        if ($hasFile) {
            /** @var UploadedFileInterface $file */
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors['file'] = 'A fájl feltöltése sikertelen volt.';
            } elseif ((int) $file->getSize() > self::MAX_SIZE) {
                $errors['file'] = 'A fájl mérete legfeljebb 20 MB lehet.';
            } else {
                $ext = strtolower(pathinfo((string) ($file->getClientFilename() ?? ''), PATHINFO_EXTENSION));
                $expected = $kind === 'docx' ? 'docx' : 'pdf';
                if ($ext !== $expected) {
                    $errors['file'] = $kind === 'docx'
                        ? 'A DOCX típushoz .docx fájlt tölts fel.'
                        : 'A PDF rátéthez .pdf fájlt tölts fel.';
                }
            }
        }

        return $errors;
    }

    private function slug(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '_', $value) ?? 'sablon';
        $value = trim($value, '_');

        return $value !== '' ? mb_substr($value, 0, 60) : 'sablon';
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
