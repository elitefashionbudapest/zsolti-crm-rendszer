<?php

declare(strict_types=1);

use App\Http\Controllers\SuperAdmin\OfficesController;
use App\Http\Controllers\SuperAdmin\StaffController;
use Slim\Routing\RouteCollectorProxy;

/**
 * Szuperadmin: irodák és dolgozók kezelése (globális, minden irodára).
 */
return static function (RouteCollectorProxy $g): void {
    // Irodák
    $g->get('/irodak', [OfficesController::class, 'index']);
    $g->get('/irodak/uj', [OfficesController::class, 'create']);
    $g->post('/irodak', [OfficesController::class, 'store']);
    $g->get('/irodak/{id:[0-9]+}/szerkesztes', [OfficesController::class, 'edit']);
    $g->post('/irodak/{id:[0-9]+}', [OfficesController::class, 'update']);
    $g->post('/irodak/{id:[0-9]+}/torles', [OfficesController::class, 'destroy']);

    // Dolgozók
    $g->get('/dolgozok', [StaffController::class, 'index']);
    $g->get('/dolgozok/uj', [StaffController::class, 'create']);
    $g->post('/dolgozok', [StaffController::class, 'store']);
    $g->get('/dolgozok/{id:[0-9]+}/szerkesztes', [StaffController::class, 'edit']);
    $g->post('/dolgozok/{id:[0-9]+}', [StaffController::class, 'update']);
    $g->post('/dolgozok/{id:[0-9]+}/allapot', [StaffController::class, 'deactivate']);
};
