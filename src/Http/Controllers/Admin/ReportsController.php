<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Riportok modul — kizárólag olvasható elemzőfelület.
 * Minden lekérdezés az aktuális irodára (office_id) szűrve fut, és try/catch
 * burkolja, így egy hiányzó tábla vagy oszlop soha nem töri meg az oldalt.
 */
final class ReportsController
{
    /** A terméktípus-kódok és magyar címkéik, rögzített sorrendben. */
    private const CATEGORIES = [
        'elet_egeszseg' => 'Élet- és egészség',
        'vagyon' => 'Vagyon',
        'nyugdij_megtakaritas' => 'Nyugdíj és megtakarítás',
        'befektetes' => 'Befektetés',
    ];

    /** A lead-fázisok és magyar címkéik, rögzített sorrendben. */
    private const STAGES = [
        'new' => 'Új',
        'contacted' => 'Megkeresve',
        'offer' => 'Ajánlat',
        'won' => 'Megnyert',
        'lost' => 'Elvesztett',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $officeId = (int) ($this->auth->user()['office_id'] ?? 0);

        $totalClients = $this->count('clients', $officeId);
        $totalContracts = $this->count('contracts', $officeId);
        $totalLeads = $this->count('leads', $officeId);

        // Szerződések terméktípus szerint
        $categoryCounts = $this->countByCategory($officeId);
        $categories = [];
        $categoryMax = 0;
        foreach (self::CATEGORIES as $code => $label) {
            $value = $categoryCounts[$code] ?? 0;
            $categoryMax = max($categoryMax, $value);
            $categories[] = ['code' => $code, 'label' => $label, 'count' => $value];
        }
        foreach ($categories as &$cat) {
            $cat['percent'] = $categoryMax > 0 ? (int) round($cat['count'] / $categoryMax * 100) : 0;
        }
        unset($cat);

        // Leadek fázis szerint
        $stageCounts = $this->countByStage($officeId);
        $stages = [];
        foreach (self::STAGES as $code => $label) {
            $stages[] = ['code' => $code, 'label' => $label, 'count' => $stageCounts[$code] ?? 0];
        }

        // Jutalékok: függő vs rendezett összeg
        $commissionPending = $this->commissionSum($officeId, 'pending');
        $commissionSettled = $this->commissionSum($officeId, 'settled');

        // Közelgő lejáratok: 30 napon belül lejáró szerződések
        $upcomingExpiries = $this->upcomingExpiries($officeId);

        return $this->twig->render($response, 'admin/reports/index.twig', [
            'active' => 'reports',
            'totalClients' => $totalClients,
            'totalContracts' => $totalContracts,
            'totalLeads' => $totalLeads,
            'upcomingExpiries' => $upcomingExpiries,
            'categories' => $categories,
            'stages' => $stages,
            'commissionPending' => $commissionPending,
            'commissionSettled' => $commissionSettled,
        ]);
    }

    /** Sorok száma egy tenant-szűrt táblában; hiba esetén 0. */
    private function count(string $table, int $officeId): int
    {
        try {
            $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) AS c FROM %s WHERE office_id = :o', $table));
            $stmt->execute(['o' => $officeId]);

            return (int) ($stmt->fetch()['c'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Szerződések darabszáma terméktípus-kódonként.
     *
     * @return array<string,int>
     */
    private function countByCategory(int $officeId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT category, COUNT(*) AS c FROM contracts WHERE office_id = :o GROUP BY category'
            );
            $stmt->execute(['o' => $officeId]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) ($row['category'] ?? '')] = (int) ($row['c'] ?? 0);
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Leadek darabszáma fázisonként.
     *
     * @return array<string,int>
     */
    private function countByStage(int $officeId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT stage, COUNT(*) AS c FROM leads WHERE office_id = :o GROUP BY stage'
            );
            $stmt->execute(['o' => $officeId]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) ($row['stage'] ?? '')] = (int) ($row['c'] ?? 0);
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Jutalékok összege adott státuszra (pending|settled); hiba esetén 0. */
    private function commissionSum(int $officeId, string $status): float
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS s FROM commissions WHERE office_id = :o AND status = :st'
            );
            $stmt->execute(['o' => $officeId, 'st' => $status]);

            return (float) ($stmt->fetch()['s'] ?? 0);
        } catch (Throwable) {
            return 0.0;
        }
    }

    /** A következő 30 napban lejáró szerződések száma; hiba esetén 0. */
    private function upcomingExpiries(int $officeId): int
    {
        try {
            $today = date('Y-m-d');
            $limit = date('Y-m-d', strtotime('+30 days'));
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS c FROM contracts'
                . ' WHERE office_id = :o AND end_date IS NOT NULL'
                . ' AND end_date >= :today AND end_date <= :limit'
            );
            $stmt->execute(['o' => $officeId, 'today' => $today, 'limit' => $limit]);

            return (int) ($stmt->fetch()['c'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
