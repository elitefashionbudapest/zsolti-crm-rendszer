<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Excel-szerű, lapos ügyfél/szerződés táblázat — a Munkafüzet1.xlsx oszlopaival.
 * Szerződésenként egy sor, az ügyfél adataival együtt. Kereshető, szűrhető.
 */
final class ExcelViewController
{
    private const CATEGORIES = [
        'elet_egeszseg' => 'Élet/eü.',
        'vagyon' => 'Vagyon',
        'nyugdij_megtakaritas' => 'Nyugdíj/megt.',
        'befektetes' => 'Befektetés',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $officeId = (int) ($this->auth->officeId() ?? 0);
        $q = (array) $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $category = (string) ($q['category'] ?? '');
        $status = (string) ($q['status'] ?? '');
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = 50;

        $where = ['ct.office_id = :office'];
        $params = ['office' => $officeId];
        if ($search !== '') {
            $where[] = '(cl.name LIKE :s OR ct.policy_number LIKE :s OR ct.insurer_name LIKE :s OR ct.module_name LIKE :s OR cl.email LIKE :s)';
            $params['s'] = '%' . $search . '%';
        }
        if ($category !== '') {
            $where[] = 'ct.category = :cat';
            $params['cat'] = $category;
        }
        if ($status !== '') {
            $where[] = 'ct.status = :st';
            $params['st'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM contracts ct LEFT JOIN clients cl ON cl.id = ct.client_id WHERE $whereSql"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT ct.*, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone, cl.mobile AS client_mobile
             FROM contracts ct LEFT JOIN clients cl ON cl.id = ct.client_id
             WHERE $whereSql
             ORDER BY cl.name ASC, ct.id ASC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);

        return $this->twig->render($response, 'admin/excel/index.twig', [
            'active' => 'excel',
            'rows' => $stmt->fetchAll(),
            'categories' => self::CATEGORIES,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'search' => $search,
            'category' => $category,
            'status' => $status,
        ]);
    }
}
