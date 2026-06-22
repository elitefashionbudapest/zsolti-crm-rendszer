<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\TemplatesController;
use Slim\Routing\RouteCollectorProxy;

/**
 * Dokumentumsablonok (PDF rátét + DOCX kitöltés) admin útvonalai.
 */
return static function (RouteCollectorProxy $g): void {
    $g->get('/sablonok', [TemplatesController::class, 'index']);
    $g->get('/sablonok/uj', [TemplatesController::class, 'create']);
    $g->post('/sablonok', [TemplatesController::class, 'store']);
    $g->get('/sablonok/{id:[0-9]+}/szerkesztes', [TemplatesController::class, 'edit']);
    $g->post('/sablonok/{id:[0-9]+}', [TemplatesController::class, 'update']);
    $g->post('/sablonok/{id:[0-9]+}/torles', [TemplatesController::class, 'destroy']);
    $g->get('/sablonok/{id:[0-9]+}/kitoltes', [TemplatesController::class, 'fillForm']);
    $g->post('/sablonok/{id:[0-9]+}/kitoltes', [TemplatesController::class, 'fill']);
};
