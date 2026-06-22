<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class DashboardController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $officeId = (int) ($this->auth->user()['office_id'] ?? 0);

        return $this->twig->render($response, 'admin/dashboard.twig', [
            'active' => 'dashboard',
            'stats' => [
                ['label' => 'Aktív ügyfelek', 'value' => $this->count('clients', $officeId), 'icon' => 'users', 'iconWrap' => 'bg-ink/5 text-ink', 'trend' => 'iroda', 'trendIcon' => 'building-2', 'trendCls' => 'bg-success/10 text-success'],
                ['label' => 'Szerződések', 'value' => $this->count('contracts', $officeId), 'icon' => 'file-text', 'iconWrap' => 'bg-teal/10 text-teal', 'trend' => 'összes', 'trendIcon' => 'layers', 'trendCls' => 'bg-teal/10 text-teal'],
                ['label' => 'Közelgő lejáratok', 'value' => '—', 'icon' => 'file-clock', 'iconWrap' => 'bg-warning/10 text-warning', 'trend' => '30 nap', 'trendIcon' => 'clock', 'trendCls' => 'bg-warning/10 text-warning'],
                ['label' => 'Jóváhagyásra váró AI', 'value' => '—', 'icon' => 'sparkles', 'iconWrap' => 'bg-gold/15 text-[#B8860B]', 'trend' => 'új', 'trendIcon' => 'zap', 'trendCls' => 'bg-gold/15 text-[#B8860B]'],
            ],
            'todos' => [],
            'activity' => [],
        ]);
    }

    private function count(string $table, int $officeId): string
    {
        try {
            $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) AS c FROM %s WHERE office_id = :o', $table));
            $stmt->execute(['o' => $officeId]);
            $row = $stmt->fetch();
            return number_format((int) ($row['c'] ?? 0), 0, ',', ' ');
        } catch (Throwable) {
            return '0';
        }
    }
}
