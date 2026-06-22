<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplatesController;
use App\Http\Controllers\Admin\EmailWorkflowsController;
use App\Http\Controllers\Admin\InsurerDispatchController;
use Slim\Routing\RouteCollectorProxy;

/**
 * E-mail folyamatok modul: sablonok, folyamatok, napló és biztosítói küldés.
 */
return static function (RouteCollectorProxy $g): void {
    // E-mail sablonok
    $g->get('/email-sablonok', [EmailTemplatesController::class, 'index']);
    $g->get('/email-sablonok/uj', [EmailTemplatesController::class, 'create']);
    $g->post('/email-sablonok', [EmailTemplatesController::class, 'store']);
    $g->get('/email-sablonok/{id:[0-9]+}/szerkesztes', [EmailTemplatesController::class, 'edit']);
    $g->post('/email-sablonok/{id:[0-9]+}', [EmailTemplatesController::class, 'update']);
    $g->post('/email-sablonok/{id:[0-9]+}/torles', [EmailTemplatesController::class, 'destroy']);

    // E-mail folyamatok
    $g->get('/email-folyamatok', [EmailWorkflowsController::class, 'index']);
    $g->get('/email-folyamatok/uj', [EmailWorkflowsController::class, 'create']);
    $g->post('/email-folyamatok', [EmailWorkflowsController::class, 'store']);
    $g->get('/email-folyamatok/{id:[0-9]+}/szerkesztes', [EmailWorkflowsController::class, 'edit']);
    $g->post('/email-folyamatok/{id:[0-9]+}', [EmailWorkflowsController::class, 'update']);
    $g->post('/email-folyamatok/{id:[0-9]+}/torles', [EmailWorkflowsController::class, 'destroy']);
    $g->post('/email-folyamatok/{id:[0-9]+}/futtatas', [EmailWorkflowsController::class, 'run']);

    // E-mail napló (csak olvasható)
    $g->get('/email-naplo', [EmailLogController::class, 'index']);

    // Biztosítói küldés
    $g->get('/biztositoi-kuldes', [InsurerDispatchController::class, 'show']);
    $g->post('/biztositoi-kuldes', [InsurerDispatchController::class, 'send']);
};
