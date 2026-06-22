<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdvisoryAiController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/tanacsadas/szerkeszto', [AdvisoryAiController::class, 'editor']);
    $g->post('/tanacsadas/general', [AdvisoryAiController::class, 'generate']);
    $g->post('/tanacsadas/mentes', [AdvisoryAiController::class, 'save']);
};
