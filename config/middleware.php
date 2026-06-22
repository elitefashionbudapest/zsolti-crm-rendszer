<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\StartSessionMiddleware;
use App\Http\Middleware\TwigGlobalsMiddleware;
use Slim\App;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Globális middleware-réteg. A hozzáadás sorrendje fordított a futáshoz (LIFO):
 * a legutoljára hozzáadott fut legkívül (legelőször befelé).
 *
 * Befelé futási sorrend: Hiba → Biztonsági fejlécek → Session → Body-parsing →
 * Twig → CSRF Guard → Twig-globálisok → Routing → kezelő.
 */
return static function (App $app, array $settings): void {
    $c = $app->getContainer();

    $app->addRoutingMiddleware();
    $app->add($c->get(TwigGlobalsMiddleware::class));
    $app->add($c->get(Guard::class));
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
    $app->addBodyParsingMiddleware();
    $app->add(new StartSessionMiddleware($settings['session'], $c->get(Auth::class)));
    $app->add($c->get(SecurityHeadersMiddleware::class));

    $app->addErrorMiddleware(
        (bool) $settings['app']['debug'],
        true,
        true,
        $c->get(\Psr\Log\LoggerInterface::class),
    );
};
