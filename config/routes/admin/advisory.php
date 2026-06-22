<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdvisoryController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/tanacsadas', [AdvisoryController::class, 'index']);
    $g->get('/tanacsadas/uj', [AdvisoryController::class, 'create']);
    $g->post('/tanacsadas', [AdvisoryController::class, 'store']);
    $g->get('/tanacsadas/{id:[0-9]+}/szerkesztes', [AdvisoryController::class, 'edit']);
    $g->post('/tanacsadas/{id:[0-9]+}', [AdvisoryController::class, 'update']);
    $g->post('/tanacsadas/{id:[0-9]+}/torles', [AdvisoryController::class, 'destroy']);
};
