<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Http\Controllers\Admin\ClientsController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Portal\HomeController as PortalHome;
use App\Http\Controllers\Public\LandingController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboard;
use App\Http\Middleware\AuthGuard;
use App\Http\Middleware\NoIndexMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $c = $app->getContainer();
    $auth = $c->get(Auth::class);
    $rf = $c->get(ResponseFactoryInterface::class);
    $noIndex = $c->get(NoIndexMiddleware::class);

    // --- Nyilvános ---
    $app->get('/', LandingController::class);
    $app->get('/egeszseg', HealthController::class);

    // --- Belépés ---
    $app->get('/belepes/ugynok', [LoginController::class, 'showAgent']);
    $app->post('/belepes/ugynok', [LoginController::class, 'agentLogin']);
    $app->get('/belepes/ugyfel', [LoginController::class, 'showClient']);
    $app->post('/belepes/ugyfel', [LoginController::class, 'clientLogin']);
    $app->post('/kilepes', [LoginController::class, 'logout']);

    // --- Admin (iroda dolgozói) ---
    $app->group('/admin', function (RouteCollectorProxy $g): void {
        $g->get('', AdminDashboard::class);
        $g->get('/', AdminDashboard::class);

        // Partnerek (ügyfelek)
        $g->get('/partnerek', [ClientsController::class, 'index']);
        $g->get('/partnerek/uj', [ClientsController::class, 'create']);
        $g->post('/partnerek', [ClientsController::class, 'store']);
        $g->get('/partnerek/{id:[0-9]+}', [ClientsController::class, 'show']);
        $g->get('/partnerek/{id:[0-9]+}/szerkesztes', [ClientsController::class, 'edit']);
        $g->post('/partnerek/{id:[0-9]+}', [ClientsController::class, 'update']);
        $g->post('/partnerek/{id:[0-9]+}/torles', [ClientsController::class, 'destroy']);
        $g->post('/partnerek/{id:[0-9]+}/uzenet', [ClientsController::class, 'sendMessage']);
        $g->post('/partnerek/{id:[0-9]+}/megjegyzes', [ClientsController::class, 'addNote']);

        // További admin modulok (szerződések, dokumentumok, feladatok, leadek, …)
        foreach (glob(__DIR__ . '/routes/admin/*.php') ?: [] as $moduleRoutes) {
            (require $moduleRoutes)($g);
        }
    })
        ->add($noIndex)
        ->add(new AuthGuard($auth, $rf, ['owner', 'assistant', 'super_admin'], '/belepes/ugynok'));

    // --- Ügyfélportál ---
    $app->group('/portal', function (RouteCollectorProxy $g): void {
        $g->get('', PortalHome::class);
        $g->get('/', PortalHome::class);

        foreach (glob(__DIR__ . '/routes/portal/*.php') ?: [] as $moduleRoutes) {
            (require $moduleRoutes)($g);
        }
    })
        ->add($noIndex)
        ->add(new AuthGuard($auth, $rf, ['client'], '/belepes/ugyfel'));

    // --- Szuperadmin ---
    $app->group('/superadmin', function (RouteCollectorProxy $g): void {
        $g->get('', SuperAdminDashboard::class);
        $g->get('/', SuperAdminDashboard::class);

        foreach (glob(__DIR__ . '/routes/superadmin/*.php') ?: [] as $moduleRoutes) {
            (require $moduleRoutes)($g);
        }
    })
        ->add($noIndex)
        ->add(new AuthGuard($auth, $rf, ['super_admin'], '/belepes/ugynok'));
};
