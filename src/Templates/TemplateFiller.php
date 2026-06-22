<?php

declare(strict_types=1);

namespace App\Templates;

use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Sablon-kitöltő szolgáltatás (adatbázis nélküli, tiszta osztály).
 *
 * Kétféle kitöltést támogat:
 *  - DOCX: a Word-sablon ${placeholder} jelölőit cseréli ki a megadott értékekre.
 *  - Overlay: egy meglévő (lapos) PDF lapjaira írja rá a szöveges értékeket
 *    a megadott (x, y) koordinátákon.
 *
 * A kitöltés robusztus: a hiányos vagy hibás bejegyzéseket kihagyja, egyetlen
 * rossz bejegyzés sem szakítja meg a teljes folyamatot.
 */
final class TemplateFiller
{
    /**
     * DOCX-sablon kitöltése a PhpWord TemplateProcessor segítségével.
     *
     * @param array<string,string> $map  placeholder => adatkulcs leképezés
     * @param array<string,mixed>  $data adatkulcs => érték
     */
    public function fillDocx(string $templateFullPath, array $map, array $data, string $outFullPath): void
    {
        try {
            $processor = new TemplateProcessor($templateFullPath);
        } catch (Throwable $e) {
            throw new RuntimeException('A DOCX-sablon nem nyitható meg: ' . $e->getMessage(), 0, $e);
        }

        foreach ($map as $placeholder => $key) {
            // Hibás leképezést (nem string kulcs/érték) kihagyunk.
            if (!is_string($placeholder) || $placeholder === '' || !is_string($key)) {
                continue;
            }
            $value = $data[$key] ?? '';
            try {
                $processor->setValue($placeholder, (string) $value);
            } catch (Throwable) {
                // Egyetlen rossz jelölő ne állítsa meg a kitöltést.
                continue;
            }
        }

        try {
            $processor->saveAs($outFullPath);
        } catch (Throwable $e) {
            throw new RuntimeException('A kitöltött DOCX nem menthető: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * PDF rátét: a forrás PDF minden lapját importálja, és az adott lapra eső
     * bejegyzéseket a megadott koordinátákon szövegként ráírja.
     *
     * A koordináták egysége az FPDI/FPDF alapértelmezése szerint pont (pt),
     * az origó a lap bal felső sarka. (1 pt = 1/72 inch; 1 mm ≈ 2,8346 pt.)
     *
     * @param array<int,array{page?:int,x?:float|int,y?:float|int,size?:int,key?:string}> $entries
     * @param array<string,mixed> $data adatkulcs => érték
     */
    public function fillOverlay(string $templateFullPath, array $entries, array $data, string $outFullPath): void
    {
        $pdf = new Fpdi('P', 'pt');

        try {
            $pageCount = $pdf->setSourceFile($templateFullPath);
        } catch (Throwable $e) {
            throw new RuntimeException('A forrás PDF nem importálható (csak lapos, nem titkosított PDF támogatott): ' . $e->getMessage(), 0, $e);
        }

        // A bejegyzéseket lap szerint csoportosítjuk a könnyebb feldolgozáshoz.
        $byPage = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $page = (int) ($entry['page'] ?? 1);
            $byPage[$page][] = $entry;
        }

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            try {
                $tplId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($tplId);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId);
            } catch (Throwable $e) {
                throw new RuntimeException('A(z) ' . $pageNo . '. oldal nem importálható: ' . $e->getMessage(), 0, $e);
            }

            foreach ($byPage[$pageNo] ?? [] as $entry) {
                $key = $entry['key'] ?? null;
                if (!is_string($key) || $key === '' || !array_key_exists($key, $data)) {
                    // Hiányzó kulcsú bejegyzést kihagyunk.
                    continue;
                }
                $value = (string) ($data[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                $x = (float) ($entry['x'] ?? 0);
                $y = (float) ($entry['y'] ?? 0);
                $fontSize = (int) ($entry['size'] ?? 10);
                if ($fontSize < 4) {
                    $fontSize = 10;
                }

                try {
                    $pdf->SetFontSize($fontSize);
                    $pdf->SetXY($x, $y);
                    $pdf->Text($x, $y, $value);
                } catch (Throwable) {
                    // Egyetlen rossz bejegyzés ne állítsa meg a kitöltést.
                    continue;
                }
            }
        }

        try {
            $pdf->Output('F', $outFullPath);
        } catch (Throwable $e) {
            throw new RuntimeException('A kitöltött PDF nem menthető: ' . $e->getMessage(), 0, $e);
        }
    }
}
