<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Documents\DocumentRepository;
use App\Documents\DocumentStorage;
use App\Support\AuditLogger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Dokumentumok kezelése (ügynöki oldal): lista, feltöltés, letöltés, törlés —
 * tenant-tudatosan (office_id).
 */
final class DocumentsController
{
    private const MAX_SIZE = 20 * 1024 * 1024;

    private const ALLOWED_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private DocumentRepository $documents,
        private DocumentStorage $storage,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $clientId = isset($q['client_id']) && $q['client_id'] !== '' ? (int) $q['client_id'] : null;
        $page = max(1, (int) ($q['page'] ?? 1));

        $result = $this->documents->paginate($search, $clientId, $page);

        return $this->twig->render($response, 'admin/documents/index.twig', [
            'active' => 'documents',
            'list' => $result,
            'search' => $search,
            'clientId' => $clientId,
            'clients' => $this->documents->clientsForOffice(),
            'flash' => $this->flash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/documents/form.twig', [
            'active' => 'documents',
            'clients' => $this->documents->clientsForOffice(),
            'errors' => [],
            'old' => ['client_id' => null, 'type' => null, 'visibility' => 'agent_only'],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $clientId = isset($body['client_id']) && $body['client_id'] !== '' ? (int) $body['client_id'] : null;
        $type = trim((string) ($body['type'] ?? ''));
        $visibility = (string) ($body['visibility'] ?? 'agent_only');
        if (!in_array($visibility, ['agent_only', 'shared'], true)) {
            $visibility = 'agent_only';
        }

        $file = $request->getUploadedFiles()['file'] ?? null;

        $error = $this->validateFile($file);
        if ($error !== null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => $error];

            return $this->twig->render($response->withStatus(422), 'admin/documents/form.twig', [
                'active' => 'documents',
                'clients' => $this->documents->clientsForOffice(),
                'errors' => ['file' => $error],
                'old' => ['client_id' => $clientId, 'type' => $type, 'visibility' => $visibility],
                'flash' => ['type' => 'error', 'msg' => $error],
            ]);
        }

        /** @var UploadedFileInterface $file */
        try {
            $stored = $this->storage->save((int) $this->auth->officeId(), $file);
        } catch (Throwable) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A fájl mentése nem sikerült.'];

            return $this->redirect($response, '/admin/dokumentumok/uj');
        }

        $id = $this->documents->create([
            'client_id' => $clientId,
            'contract_id' => null,
            'type' => $type !== '' ? $type : 'egyéb',
            'original_name' => $stored['original_name'],
            'stored_path' => $stored['stored_path'],
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'visibility' => $visibility,
            'uploaded_by' => $this->auth->id(),
        ]);
        $this->audit->log('document.upload', 'document', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dokumentum feltöltve.'];

        return $this->redirect($response, '/admin/dokumentumok');
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $doc = $this->documents->find((int) $args['id']);
        if ($doc === null) {
            return $response->withStatus(404);
        }

        $stream = (new StreamFactory())->createStreamFromFile($this->storage->fullPath((string) $doc['stored_path']));

        return $response
            ->withHeader('Content-Type', (string) $doc['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename((string) $doc['original_name']) . '"')
            ->withBody($stream);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $doc = $this->documents->find($id);
        if ($doc !== null) {
            $this->storage->delete((string) $doc['stored_path']);
            $this->documents->delete($id);
            $this->audit->log('document.delete', 'document', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dokumentum törölve.'];
        }

        return $this->redirect($response, '/admin/dokumentumok');
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
