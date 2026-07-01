<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\InboxController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/postalada', [InboxController::class, 'index']);
    $g->post('/postalada/szinkron', [InboxController::class, 'sync']);
    $g->post('/postalada/{id}/partner', [InboxController::class, 'assignClient']);
    $g->post('/postalada/melleklet/{id}/mentes', [InboxController::class, 'saveAttachment']);
};
