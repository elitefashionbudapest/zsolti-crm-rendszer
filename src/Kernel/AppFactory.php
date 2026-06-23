<?php

declare(strict_types=1);

namespace App\Kernel;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Az alkalmazás összeállítása: környezet, beállítások, DI-konténer, Slim app,
 * útvonalak és middleware-réteg.
 */
final class AppFactory
{
    public static function create(string $root): App
    {
        // A .env a repó gyökerében, a szülő- vagy a nagyszülőmappában lehet — így a
        // szerveren a webgyökéren KÍVÜL tartható (se git-piszok, se web-elérhetőség).
        $envDir = null;
        foreach ([$root, dirname($root), dirname($root, 2)] as $candidate) {
            if (is_file($candidate . '/.env')) {
                $envDir = $candidate;
                break;
            }
        }
        if ($envDir !== null) {
            Dotenv::createImmutable($envDir)->safeLoad();
        }

        /** @var array<string,mixed> $settings */
        $settings = (require $root . '/config/settings.php')($root);
        date_default_timezone_set($settings['app']['timezone']);

        // A munkamenetet a bootstrap során indítjuk (a CSRF Guard aktív sessiont vár).
        self::startSession($settings['session']);

        // A konténer-definíciók innen olvassák a beállításokat.
        $GLOBALS['__app_settings'] = $settings;

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $root . '/config/container.php');
        $container = $builder->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        // Alkönyvtáras telepítés (pl. /zsolti_crm): a Slim ezzel illeszti az útvonalakat.
        $basePath = (string) ($settings['app']['base_path'] ?? '');
        if ($basePath !== '') {
            $app->setBasePath($basePath);
        }

        (require $root . '/config/routes.php')($app);
        (require $root . '/config/middleware.php')($app, $settings);

        return $app;
    }

    /** @param array<string,mixed> $session */
    private static function startSession(array $session): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => (int) ($session['lifetime'] ?? 7200),
            'path' => '/',
            'httponly' => true,
            'secure' => (bool) ($session['secure'] ?? true),
            'samesite' => 'Lax',
        ]);
        session_name((string) ($session['name'] ?? 'aegis_session'));
        session_start();
    }
}

