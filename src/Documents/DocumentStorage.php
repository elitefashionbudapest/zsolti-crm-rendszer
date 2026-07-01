<?php

declare(strict_types=1);

namespace App\Documents;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Biztonságos fájltárolás a webgyökéren kívül. A fájlok irodánkénti almappában,
 * véletlen néven tárolódnak; az eredeti név csak metaadatként marad meg.
 */
final class DocumentStorage
{
    public function __construct(private string $basePath)
    {
    }

    /**
     * @return array{stored_path: string, original_name: string, mime: string, size: int}
     */
    public function save(int $officeId, UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Hibás fájlfeltöltés.');
        }

        $original = (string) ($file->getClientFilename() ?? 'fajl');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

        $dir = $this->basePath . '/office_' . $officeId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $file->moveTo($dir . '/' . $name);

        return [
            'stored_path' => 'office_' . $officeId . '/' . $name,
            'original_name' => $original,
            'mime' => (string) ($file->getClientMediaType() ?? 'application/octet-stream'),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * Nyers bájtok tárolása (pl. e-mail-melléklet), a save()-vel azonos elrendezésben.
     *
     * @return array{stored_path: string, original_name: string, mime: string, size: int}
     */
    public function saveBytes(int $officeId, string $bytes, string $originalName, string $mime = 'application/octet-stream'): array
    {
        $original = $originalName !== '' ? $originalName : 'fajl';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

        $dir = $this->basePath . '/office_' . $officeId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        file_put_contents($dir . '/' . $name, $bytes);

        return [
            'stored_path' => 'office_' . $officeId . '/' . $name,
            'original_name' => $original,
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
            'size' => strlen($bytes),
        ];
    }

    public function fullPath(string $storedPath): string
    {
        // Útvonal-bejárás elleni védelem: a feloldott útvonal a basePath alatt maradjon.
        $full = $this->basePath . '/' . ltrim($storedPath, '/');
        $real = realpath($full);
        $base = realpath($this->basePath);
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            throw new RuntimeException('Érvénytelen fájlútvonal.');
        }

        return $real;
    }

    public function delete(string $storedPath): void
    {
        try {
            $full = $this->fullPath($storedPath);
            if (is_file($full)) {
                unlink($full);
            }
        } catch (RuntimeException) {
            // nincs teendő
        }
    }
}
