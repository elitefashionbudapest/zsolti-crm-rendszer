<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use Slim\Csrf\Guard;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Közös Twig-globálisok: a belépett felhasználó és a CSRF-token.
 */
final class TwigGlobalsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private Guard $csrf,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $env = $this->twig->getEnvironment();
        $env->addGlobal('current_user', $this->auth->user());

        $nameKey = $this->csrf->getTokenNameKey();
        $valueKey = $this->csrf->getTokenValueKey();
        $env->addGlobal('csrf', [
            'keys' => ['name' => $nameKey, 'value' => $valueKey],
            'name' => $request->getAttribute($nameKey),
            'value' => $request->getAttribute($valueKey),
        ]);

        return $handler->handle($request);
    }
}
