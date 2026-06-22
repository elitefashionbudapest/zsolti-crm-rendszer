<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\AdvisoryController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/tanacsadas', [AdvisoryController::class, 'index']);
    $g->get('/tanacsadas/{id:[0-9]+}', [AdvisoryController::class, 'show']);
};
