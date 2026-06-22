<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DocumentsController;
use Slim\Routing\RouteCollectorProxy;

/**
 * Dokumentumok (ügynöki oldal) admin útvonalai.
 */
return static function (RouteCollectorProxy $g): void {
    $g->get('/dokumentumok', [DocumentsController::class, 'index']);
    $g->get('/dokumentumok/uj', [DocumentsController::class, 'create']);
    $g->post('/dokumentumok', [DocumentsController::class, 'store']);
    $g->get('/dokumentumok/{id:[0-9]+}/letoltes', [DocumentsController::class, 'download']);
    $g->post('/dokumentumok/{id:[0-9]+}/torles', [DocumentsController::class, 'destroy']);
};
