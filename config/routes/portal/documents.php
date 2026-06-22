<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\DocumentsController;
use Slim\Routing\RouteCollectorProxy;

/**
 * Dokumentumaim (ügyfélportál) útvonalai — csak a saját, megosztott dokumentumok.
 */
return static function (RouteCollectorProxy $g): void {
    $g->get('/dokumentumaim', [DocumentsController::class, 'index']);
    $g->get('/dokumentumaim/{id:[0-9]+}/letoltes', [DocumentsController::class, 'download']);
};
