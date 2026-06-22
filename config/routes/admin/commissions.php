<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CommissionsController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    // Jutalékok
    $g->get('/jutalekok', [CommissionsController::class, 'index']);
    $g->get('/jutalekok/uj', [CommissionsController::class, 'create']);
    $g->post('/jutalekok', [CommissionsController::class, 'store']);
    $g->get('/jutalekok/{id:[0-9]+}/szerkesztes', [CommissionsController::class, 'edit']);
    $g->post('/jutalekok/{id:[0-9]+}', [CommissionsController::class, 'update']);
    $g->post('/jutalekok/{id:[0-9]+}/torles', [CommissionsController::class, 'destroy']);
    $g->post('/jutalekok/{id:[0-9]+}/rendezes', [CommissionsController::class, 'settle']);
};
