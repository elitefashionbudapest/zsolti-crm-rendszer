<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Biztonságos munkamenet indítása (httponly, secure, samesite) és a
 * tenant-kontextus beállítása a session alapján.
 */
final class StartSessionMiddleware implements MiddlewareInterface
{
    /** @param array<string,mixed> $session */
    public function __construct(
        private array $session,
        private Auth $auth,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => (int) ($this->session['lifetime'] ?? 7200),
                'path' => '/',
                'httponly' => true,
                'secure' => (bool) ($this->session['secure'] ?? true),
                'samesite' => 'Lax',
            ]);
            session_name((string) ($this->session['name'] ?? 'aegis_session'));
            session_start();
        }

        $this->auth->bindTenant();

        return $handler->handle($request);
    }
}
