<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Alkönyvtáras telepítésnél (pl. /zsolti_crm) a gyökér-abszolút URL-eket
 * ellátja az előtaggal, hogy a sablonokat ne kelljen átírni. Kezeli a
 * Location fejlécet és a HTML-ben a href/src/action és fetch() hivatkozásokat.
 * Gyökérnél (üres base path) nem csinál semmit.
 */
final class BasePathMiddleware implements MiddlewareInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        $bp = $this->basePath;
        if ($bp === '') {
            return $response;
        }

        try {
            // 1) Átirányítás (Location) előtagolása
            if ($response->hasHeader('Location')) {
                $loc = $response->getHeaderLine('Location');
                if ($loc !== '' && $loc[0] === '/' && !str_starts_with($loc, '//') && !str_starts_with($loc, $bp . '/') && $loc !== $bp) {
                    $response = $response->withHeader('Location', $bp . $loc);
                }
            }

            // 2) HTML-törzs hivatkozásai. A Twig-válaszon gyakran nincs Content-Type a
            // PSR-7 objektumban (a szerver adja kimenetkor), ezért az üres CT is HTML-nek
            // számít; a JSON/kép/letöltés explicit CT-vel kimarad.
            $ct = $response->getHeaderLine('Content-Type');
            if ($ct === '' || str_contains($ct, 'text/html')) {
                $html = (string) $response->getBody();
                $html = (string) preg_replace('#\b(href|src|action)="/(?!/)#', '$1="' . $bp . '/', $html);
                $html = (string) preg_replace('#(fetch\(\s*[\'"])/(?!/)#', '${1}' . $bp . '/', $html);
                $response = $response->withBody((new StreamFactory())->createStream($html));
            }
        } catch (\Throwable) {
            // Soha ne törje meg a választ — előtagolás nélkül megy tovább.
            return $response;
        }

        return $response;
    }
}
