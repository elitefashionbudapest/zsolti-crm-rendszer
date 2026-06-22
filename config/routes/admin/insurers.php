<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\InsurersController;
use Slim\Routing\RouteCollectorProxy;

/**
 * Biztosítók és e-mail címlisták admin útvonalai.
 */
return static function (RouteCollectorProxy $g): void {
    $g->get('/biztositok', [InsurersController::class, 'index']);
    $g->get('/biztositok/uj', [InsurersController::class, 'create']);
    $g->post('/biztositok', [InsurersController::class, 'store']);
    $g->get('/biztositok/{id:[0-9]+}', [InsurersController::class, 'show']);
    $g->get('/biztositok/{id:[0-9]+}/szerkesztes', [InsurersController::class, 'edit']);
    $g->post('/biztositok/{id:[0-9]+}', [InsurersController::class, 'update']);
    $g->post('/biztositok/{id:[0-9]+}/torles', [InsurersController::class, 'destroy']);
    $g->post('/biztositok/{id:[0-9]+}/cimlista', [InsurersController::class, 'addRoute']);
    $g->post('/biztositok/{id:[0-9]+}/cimlista/{routeId:[0-9]+}/torles', [InsurersController::class, 'deleteRoute']);
};
