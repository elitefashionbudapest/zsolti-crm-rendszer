<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AiExtractionController;
use Slim\Routing\RouteCollectorProxy;

/**
 * AI adatkinyerés (Claude dokumentum-feldolgozás) admin útvonalai.
 */
return static function (RouteCollectorProxy $g): void {
    $g->get('/ai-kinyeres', [AiExtractionController::class, 'index']);
    $g->post('/ai-kinyeres', [AiExtractionController::class, 'upload']);
    $g->get('/ai-kinyeres/{id:[0-9]+}', [AiExtractionController::class, 'review']);
    $g->post('/ai-kinyeres/{id:[0-9]+}/jovahagyas', [AiExtractionController::class, 'apply']);
    $g->post('/ai-kinyeres/{id:[0-9]+}/elvetes', [AiExtractionController::class, 'reject']);
};
