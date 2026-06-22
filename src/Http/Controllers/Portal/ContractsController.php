<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ügyfélportál: a belépett ügyfél saját szerződéseinek listája (csak olvasható).
 */
final class ContractsController
{
    /** A kategóriák magyar címkéi. */
    private const CATEGORIES = [
        'elet_egeszseg' => 'Élet- és egészségbiztosítás',
        'vagyon' => 'Vagyonbiztosítás',
        'nyugdij_megtakaritas' => 'Nyugdíj és megtakarítás',
        'befektetes' => 'Befektetés / pénzügyi terv',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $officeId = $this->auth->officeId();

        $contracts = [];
        if ($clientId !== null && $officeId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, category, insurer_name, module_name, policy_number, '
                . 'start_date, end_date, anniversary, annual_fee, status '
                . 'FROM contracts WHERE client_id = :client_id AND office_id = :office_id '
                . 'ORDER BY status ASC, id DESC'
            );
            $stmt->execute(['client_id' => $clientId, 'office_id' => $officeId]);
            $contracts = $stmt->fetchAll();
        }

        return $this->twig->render($response, 'portal/contracts.twig', [
            'active' => 'contracts',
            'contracts' => $contracts,
            'categories' => self::CATEGORIES,
        ]);
    }
}
