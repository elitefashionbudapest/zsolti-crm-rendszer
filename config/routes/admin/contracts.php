<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ContractsController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/szerzodesek', [ContractsController::class, 'index']);
    $g->get('/szerzodesek/uj', [ContractsController::class, 'create']);
    $g->post('/szerzodesek', [ContractsController::class, 'store']);
    $g->get('/szerzodesek/{id:[0-9]+}', [ContractsController::class, 'show']);
    $g->get('/szerzodesek/{id:[0-9]+}/szerkesztes', [ContractsController::class, 'edit']);
    $g->post('/szerzodesek/{id:[0-9]+}', [ContractsController::class, 'update']);
    $g->post('/szerzodesek/{id:[0-9]+}/torles', [ContractsController::class, 'destroy']);
    $g->post('/szerzodesek/{id:[0-9]+}/attributumok', [ContractsController::class, 'addAttribute']);
    $g->post('/szerzodesek/{id:[0-9]+}/attributumok/{attrId:[0-9]+}/frissites', [ContractsController::class, 'updateAttribute']);
    $g->post('/szerzodesek/{id:[0-9]+}/attributumok/{attrId:[0-9]+}/torles', [ContractsController::class, 'deleteAttribute']);
};
