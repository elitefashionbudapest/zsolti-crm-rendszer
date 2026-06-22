<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class HealthController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $db = 'ok';
        try {
            $this->pdo->query('SELECT 1');
        } catch (Throwable) {
            $db = 'hiba';
        }

        $payload = [
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'db' => $db,
            'time' => date('c'),
        ];

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($db === 'ok' ? 200 : 503);
    }
}
