<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Mail\MailboxSyncDispatcher;
use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Beérkező e-mailek (postaláda) — IMAP-szinkron az iroda beállításaiból.
 */
final class InboxController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
        private MailboxSyncDispatcher $mailbox,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $officeId = (int) ($this->auth->officeId() ?? 0);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM incoming_emails WHERE office_id = :o ORDER BY received_at DESC, id DESC LIMIT 100'
        );
        $stmt->execute(['o' => $officeId]);

        return $this->twig->render($response, 'admin/inbox/index.twig', [
            'active' => 'inbox',
            'emails' => $stmt->fetchAll(),
            'configured' => $this->mailbox->isConfigured($officeId),
            'flash' => $this->flash(),
        ]);
    }

    public function sync(Request $request, Response $response): Response
    {
        $officeId = (int) ($this->auth->officeId() ?? 0);
        $result = $this->mailbox->sync($officeId);
        $this->audit->log('inbox.sync');

        if ($result['error'] !== null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Szinkronizálási hiba: ' . $result['error']];
        } else {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $result['count'] . ' új levél szinkronizálva.'];
        }

        return $response->withHeader('Location', '/admin/postalada')->withStatus(302);
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }
}
