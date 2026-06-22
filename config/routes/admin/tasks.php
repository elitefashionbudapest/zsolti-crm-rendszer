<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\TasksController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/feladatok', [TasksController::class, 'index']);
    $g->get('/feladatok/uj', [TasksController::class, 'create']);
    $g->post('/feladatok', [TasksController::class, 'store']);
    $g->get('/feladatok/{id:[0-9]+}', [TasksController::class, 'show']);
    $g->get('/feladatok/{id:[0-9]+}/szerkesztes', [TasksController::class, 'edit']);
    $g->post('/feladatok/{id:[0-9]+}', [TasksController::class, 'update']);
    $g->post('/feladatok/{id:[0-9]+}/torles', [TasksController::class, 'destroy']);
    $g->post('/feladatok/{id:[0-9]+}/kesz', [TasksController::class, 'toggle']);
};
