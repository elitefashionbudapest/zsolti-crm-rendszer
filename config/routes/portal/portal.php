<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\ContractsController;
use App\Http\Controllers\Portal\IntakeController;
use App\Http\Controllers\Portal\MessagesController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/szerzodeseim', ContractsController::class);

    $g->get('/adataim', [IntakeController::class, 'show']);
    $g->post('/adataim', [IntakeController::class, 'submit']);

    $g->get('/uzenetek', [MessagesController::class, 'show']);
    $g->post('/uzenetek', [MessagesController::class, 'send']);
};
