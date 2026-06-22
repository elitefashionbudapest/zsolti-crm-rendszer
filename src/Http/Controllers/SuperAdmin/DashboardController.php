<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class DashboardController
{
    public function __construct(
        private Twig $twig,
        private PDO $pdo,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'superadmin/dashboard.twig', [
            'offices' => $this->count('offices'),
            'users' => $this->count('users'),
        ]);
    }

    private function count(string $table): string
    {
        try {
            $row = $this->pdo->query(sprintf('SELECT COUNT(*) AS c FROM %s', $table))->fetch();
            return (string) ((int) ($row['c'] ?? 0));
        } catch (Throwable) {
            return '0';
        }
    }
}
