<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\UserRepository;
use App\Database\Connection;
use App\Support\Encryption;
use App\Tenant\TenantContext;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Csrf\Guard;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * PHP-DI konténer-definíciók.
 *
 * @return array<string,mixed>
 */
return [
    'settings' => factory(static function (): array {
        // A beállításokat az AppFactory tölti be és teszi elérhetővé globálisan.
        return $GLOBALS['__app_settings'];
    }),

    PDO::class => factory(static function (ContainerInterface $c): PDO {
        $settings = $c->get('settings');
        return Connection::create($settings['db'], $settings['root']);
    }),

    TenantContext::class => autowire()->constructor(),

    Encryption::class => factory(static function (ContainerInterface $c): Encryption {
        return new Encryption($c->get('settings')['app']['key']);
    }),

    App\Support\SignedState::class => factory(static function (ContainerInterface $c): App\Support\SignedState {
        return new App\Support\SignedState($c->get('settings')['app']['key']);
    }),

    UserRepository::class => autowire(),
    Auth::class => autowire(),

    App\Settings\SettingsService::class => autowire(),

    App\Documents\DocumentStorage::class => factory(static function (ContainerInterface $c): App\Documents\DocumentStorage {
        return new App\Documents\DocumentStorage($c->get('settings')['paths']['uploads']);
    }),

    LoggerInterface::class => factory(static function (ContainerInterface $c): LoggerInterface {
        $settings = $c->get('settings')['log'];
        $logger = new Logger('app');
        $level = Level::fromName(ucfirst((string) $settings['level']));
        $logger->pushHandler(new StreamHandler($settings['path'], $level));
        return $logger;
    }),

    ResponseFactoryInterface::class => factory(static fn (): ResponseFactoryInterface => new ResponseFactory()),

    Twig::class => factory(static function (ContainerInterface $c): Twig {
        $settings = $c->get('settings');
        $cache = $settings['app']['debug'] ? false : $settings['paths']['cache'] . '/twig';
        $twig = Twig::create($settings['paths']['templates'], [
            'cache' => $cache,
            'auto_reload' => true,
        ]);
        $twig->getEnvironment()->addGlobal('app_name', $settings['app']['name']);
        return $twig;
    }),

    Guard::class => factory(static function (ContainerInterface $c): Guard {
        $guard = new Guard($c->get(ResponseFactoryInterface::class));
        $guard->setPersistentTokenMode(true);
        return $guard;
    }),
];
