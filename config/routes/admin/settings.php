<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SettingsController;
use Slim\Routing\RouteCollectorProxy;

return static function (RouteCollectorProxy $g): void {
    $g->get('/beallitasok', [SettingsController::class, 'show']);
    $g->post('/beallitasok', [SettingsController::class, 'save']);
    $g->get('/beallitasok/gmail/connect', [SettingsController::class, 'gmailConnect']);
    $g->get('/beallitasok/gmail/callback', [SettingsController::class, 'gmailCallback']);
    $g->post('/beallitasok/gmail/disconnect', [SettingsController::class, 'gmailDisconnect']);
};
