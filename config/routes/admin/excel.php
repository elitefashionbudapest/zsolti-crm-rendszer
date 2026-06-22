<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ExcelViewController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/ugyfel-tablazat', ExcelViewController::class);
};
