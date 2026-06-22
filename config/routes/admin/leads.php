<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LeadsController;
use Slim\Routing\RouteCollectorProxy;

/**
 * Leadek (értékesítési pipeline) admin útvonalai.
 */
return static function (RouteCollectorProxy $g): void {
    $g->get('/leadek', [LeadsController::class, 'index']);
    $g->get('/leadek/uj', [LeadsController::class, 'create']);
    $g->post('/leadek', [LeadsController::class, 'store']);
    $g->get('/leadek/{id:[0-9]+}', [LeadsController::class, 'show']);
    $g->get('/leadek/{id:[0-9]+}/szerkesztes', [LeadsController::class, 'edit']);
    $g->post('/leadek/{id:[0-9]+}', [LeadsController::class, 'update']);
    $g->post('/leadek/{id:[0-9]+}/torles', [LeadsController::class, 'destroy']);
    $g->post('/leadek/{id:[0-9]+}/atalakitas', [LeadsController::class, 'convert']);
};
