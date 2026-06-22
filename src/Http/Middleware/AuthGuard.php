<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Hozzáférés-védelem útvonalcsoportonként: belépés szükséges, és a felhasználónak
 * a megengedett szerepkörök egyikével kell rendelkeznie. Hiány esetén a megfelelő
 * belépési oldalra irányít.
 */
final class AuthGuard implements MiddlewareInterface
{
    /** @param list<string> $allowedRoles */
    public function __construct(
        private Auth $auth,
        private ResponseFactoryInterface $responseFactory,
        private array $allowedRoles,
        private string $loginPath,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->auth->check() || !$this->auth->hasAnyRole($this->allowedRoles)) {
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $this->loginPath);
        }

        return $handler->handle($request);
    }
}
