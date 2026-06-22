<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Email\EmailSendRepository;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * E-mail napló (csak olvasható): a kiküldött, várakozó és sikertelen levelek listája.
 */
final class EmailLogController
{
    public function __construct(
        private Twig $twig,
        private EmailSendRepository $sends,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = (array) $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));

        return $this->twig->render($response, 'admin/email/log.twig', [
            'active' => 'emails',
            'list' => $this->sends->paginate($page),
            'flash' => $this->flash(),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }
}
