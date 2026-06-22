<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ReportsController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    // Riportok (elemzések, csak olvasható)
    $g->get('/riportok', [ReportsController::class, '__invoke']);
};
