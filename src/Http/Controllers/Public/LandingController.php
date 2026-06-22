<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LandingController
{
    public function __construct(private Twig $twig)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'public/landing.twig', [
            'features' => [
                ['icon' => 'users', 'title' => 'Partnerkezelés', 'desc' => 'Ügyfelek, szerződések és kapcsolattartási előzmények egy helyen, kereshetően és átláthatóan.'],
                ['icon' => 'scan-text', 'title' => 'AI-dokumentumfeldolgozás', 'desc' => 'Az AI másodpercek alatt kinyeri az adatokat a feltöltött szerződésekből és ajánlatokból.'],
                ['icon' => 'file-check-2', 'title' => 'Automatikus dokumentumkitöltés', 'desc' => 'A kinyert adatok automatikusan kitöltik a sablonokat — nincs többé kézi gépelés.'],
                ['icon' => 'mail', 'title' => 'E-mail folyamatok', 'desc' => 'Évforduló-emlékeztetők, lejárati értesítések és követések automatikusan, ütemezve.'],
                ['icon' => 'send', 'title' => 'Biztosítói küldés', 'desc' => 'Kész szerződések egy kattintással, közvetlenül a biztosítóhoz továbbítva.'],
                ['icon' => 'monitor-smartphone', 'title' => 'Ügyfélportál', 'desc' => 'Külön, biztonságos felület, ahol az ügyfelek elérik szerződéseiket és dokumentumaikat.'],
            ],
            'steps' => [
                ['num' => '1', 'icon' => 'upload-cloud', 'title' => 'Feltöltöd a dokumentumot', 'desc' => 'Húzd be a szerződést vagy ajánlatot — PDF, kép vagy szkennelt fájl.'],
                ['num' => '2', 'icon' => 'sparkles', 'title' => 'Az AI kinyeri az adatokat', 'desc' => 'A rendszer automatikusan felismeri és strukturálja a fontos mezőket.'],
                ['num' => '3', 'icon' => 'check-circle-2', 'title' => 'Te jóváhagyod', 'desc' => 'Egy pillantás, egy kattintás — ellenőrzöd és megerősíted az adatokat.'],
                ['num' => '4', 'icon' => 'send', 'title' => 'Kitöltöd és elküldöd', 'desc' => 'A kész dokumentum automatikusan kitöltődik és mehet a biztosítóhoz.'],
            ],
            'trust' => [
                ['icon' => 'shield-check', 'label' => 'GDPR-megfelelő adatkezelés'],
                ['icon' => 'server', 'label' => 'Saját SMTP — saját e-mail kiszolgáló'],
                ['icon' => 'sparkles', 'label' => 'AI-alapú adatkinyerés'],
                ['icon' => 'layout-panel-left', 'label' => 'Két külön felület'],
            ],
        ]);
    }
}
