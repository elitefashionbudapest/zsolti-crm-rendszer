<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PDF első N oldalának kivágása (a modellnek küldött másolathoz), hogy a nagy,
 * több száz oldalas dokumentumok ne fogyasszanak feleslegesen sok tokent/kreditet.
 * qpdf-et vagy ghostscriptet használ, ha elérhető; ha egyik sincs (vagy nem PDF),
 * az eredeti bájtokat adja vissza — a kinyerés így is működik, csak drágábban.
 * Kizárólag CLI-workerből hívjuk; a bemenet nem felhasználói shell-tartalom.
 */
final class PdfTrimmer
{
    public function firstPages(string $pdfBytes, int $pages = 10): string
    {
        if ($pdfBytes === '' || $pages < 1 || !$this->canExec()) {
            return $pdfBytes;
        }
        // Csak valódi PDF-et vágunk (a %PDF- fejléc alapján).
        if (!str_starts_with($pdfBytes, '%PDF-')) {
            return $pdfBytes;
        }

        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'pdfin_');
        if ($in === false) {
            return $pdfBytes;
        }
        $out = $in . '_out.pdf';
        file_put_contents($in, $pdfBytes);

        $result = null;

        if (($qpdf = $this->binary('qpdf')) !== null) {
            // Ha kevesebb oldal van, mint N, a qpdf a "1-N" tartományt a végéig értelmezi.
            $cmd = escapeshellarg($qpdf) . ' --pages ' . escapeshellarg($in) . ' 1-' . $pages
                . ' -- ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>/dev/null';
            @exec($cmd, $o1, $rc1);
            if (($rc1 ?? 1) === 0 && is_file($out) && filesize($out) > 0) {
                $result = (string) file_get_contents($out);
            }
        }

        if ($result === null && ($gs = $this->binary('gs')) !== null) {
            $cmd = escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite'
                . ' -dFirstPage=1 -dLastPage=' . $pages
                . ' -sOutputFile=' . escapeshellarg($out) . ' ' . escapeshellarg($in) . ' 2>/dev/null';
            @exec($cmd, $o2, $rc2);
            if (($rc2 ?? 1) === 0 && is_file($out) && filesize($out) > 0) {
                $result = (string) file_get_contents($out);
            }
        }

        @unlink($in);
        @unlink($out);

        return ($result !== null && $result !== '') ? $result : $pdfBytes;
    }

    private function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }

    private function binary(string $name): ?string
    {
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $rc);
        if (($rc ?? 1) === 0 && isset($out[0]) && $out[0] !== '') {
            return trim($out[0]);
        }

        return null;
    }
}
