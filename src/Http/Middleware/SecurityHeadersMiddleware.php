<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Biztonsági HTTP-fejlécek minden válaszra.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        $csp = "default-src 'self'; "
            . "img-src 'self' data: https:; "
            . "font-src 'self' https://fonts.gstatic.com data:; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; "
            . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }
}
