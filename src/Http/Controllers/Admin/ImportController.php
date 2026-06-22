<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Import\ExcelImporter;
use App\Support\AuditLogger;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;
use Throwable;

/**
 * Ügyfél- és szerződésadatok importja Excel- (.xlsx/.xls) fájlból az aktuális irodába.
 */
final class ImportController
{
    private const MAX_SIZE = 30 * 1024 * 1024; // 30 MB
    private const ALLOWED_EXT = ['xlsx', 'xls'];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
        private AuditLogger $audit,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/import/index.twig', [
            'active' => 'settings',
            'flash' => $this->flash(),
        ]);
    }

    public function run(Request $request, Response $response): Response
    {
        $office = $this->auth->officeId();
        if ($office === null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nincs kiválasztott iroda, az import nem hajtható végre.'];

            return $this->redirect($response, '/admin/import');
        }

        /** @var array<string,UploadedFileInterface> $files */
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nem sikerült a fájl feltöltése. Válasszon ki egy .xlsx fájlt.'];

            return $this->redirect($response, '/admin/import');
        }

        $original = (string) $file->getClientFilename();
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Csak .xlsx vagy .xls fájl tölthető fel.'];

            return $this->redirect($response, '/admin/import');
        }

        if (($file->getSize() ?? 0) > self::MAX_SIZE) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A fájl mérete legfeljebb 30 MB lehet.'];

            return $this->redirect($response, '/admin/import');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_') ?: (sys_get_temp_dir() . '/import_' . bin2hex(random_bytes(8)));
        $tmpPath .= '.' . $ext;

        try {
            $file->moveTo($tmpPath);

            $importer = new ExcelImporter($this->pdo);
            $result = $importer->import($tmpPath, $office, $this->auth->id());

            $this->audit->log('import.clients');

            $msg = sprintf(
                '%d ügyfél, %d szerződés importálva (%d kihagyva).',
                $result['clients'],
                $result['contracts'],
                $result['skipped'],
            );

            $_SESSION['flash'] = [
                'type' => $result['errors'] === [] ? 'success' : 'warning',
                'msg' => $msg,
                'errors' => $result['errors'],
            ];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Az import során hiba történt: ' . $e->getMessage()];
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return $this->redirect($response, '/admin/import');
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
