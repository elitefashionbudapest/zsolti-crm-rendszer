<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ImportController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/import', [ImportController::class, 'show']);
    $g->post('/import', [ImportController::class, 'run']);
};
