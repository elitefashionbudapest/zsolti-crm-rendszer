<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Advisory\AdvisoryRepository;
use App\Auth\Auth;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tanácsadói anyagok az ügyfélportálon: a bejelentkezett ügyfélnek szóló,
 * publikált anyagok listája és egyenkénti megtekintése.
 */
final class AdvisoryController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private AdvisoryRepository $advisory,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $rows = $clientId !== null ? $this->advisory->forClient($clientId) : [];

        return $this->twig->render($response, 'portal/advisory.twig', [
            'active' => 'home',
            'rows' => $rows,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $clientId = $this->auth->clientId();
        if ($clientId === null) {
            return $response->withStatus(404);
        }

        $id = (int) $args['id'];
        $item = null;
        foreach ($this->advisory->forClient($clientId) as $row) {
            if ((int) $row['id'] === $id) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'portal/advisory-show.twig', [
            'active' => 'home',
            'item' => $item,
        ]);
    }
}
