<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Az admin/portál/szuperadmin felületek nem indexelhetők a keresőrobotok által.
 */
final class NoIndexMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        return $handler->handle($request)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
