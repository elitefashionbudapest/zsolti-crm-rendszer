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
 * Beérkező e-mailek (postaláda) — szinkron, partnerhez rendelés, mellékletmentés.
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
            'SELECT e.*, c.name AS client_name
             FROM incoming_emails e
             LEFT JOIN clients c ON c.id = e.client_id AND c.office_id = e.office_id
             WHERE e.office_id = :o
             ORDER BY e.received_at DESC, e.id DESC LIMIT 100'
        );
        $stmt->execute(['o' => $officeId]);
        $emails = $stmt->fetchAll();

        $attByEmail = [];
        $emailIds = array_map(static fn ($e) => (int) $e['id'], $emails);
        if ($emailIds !== []) {
            $in = implode(',', array_fill(0, count($emailIds), '?'));
            $as = $this->pdo->prepare(
                "SELECT * FROM incoming_email_attachments WHERE office_id = ? AND email_id IN ($in) ORDER BY id ASC"
            );
            $as->execute(array_merge([$officeId], $emailIds));
            foreach ($as->fetchAll() as $a) {
                $attByEmail[(int) $a['email_id']][] = $a;
            }
        }

        $cs = $this->pdo->prepare('SELECT id, name FROM clients WHERE office_id = :o ORDER BY name ASC');
        $cs->execute(['o' => $officeId]);
        $clients = $cs->fetchAll();

        return $this->twig->render($response, 'admin/inbox/index.twig', [
            'active' => 'inbox',
            'emails' => $emails,
            'attachments' => $attByEmail,
            'clients' => $clients,
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

    /** E-mail hozzárendelése (vagy leválasztása) partnerhez. */
    public function assignClient(Request $request, Response $response, array $args): Response
    {
        $officeId = (int) ($this->auth->officeId() ?? 0);
        $emailId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $clientId = (int) ($body['client_id'] ?? 0);

        // A levél az irodáé?
        $chk = $this->pdo->prepare('SELECT id FROM incoming_emails WHERE id = :e AND office_id = :o LIMIT 1');
        $chk->execute(['e' => $emailId, 'o' => $officeId]);
        if ($chk->fetchColumn() === false) {
            return $this->back($response);
        }

        // A partner is az irodáé (0 = leválasztás)?
        $newClient = null;
        if ($clientId > 0) {
            $cc = $this->pdo->prepare('SELECT id FROM clients WHERE id = :c AND office_id = :o LIMIT 1');
            $cc->execute(['c' => $clientId, 'o' => $officeId]);
            if ($cc->fetchColumn() !== false) {
                $newClient = $clientId;
            }
        }

        $upd = $this->pdo->prepare('UPDATE incoming_emails SET client_id = :c, updated_at = :u WHERE id = :e AND office_id = :o');
        $upd->execute(['c' => $newClient, 'u' => date('Y-m-d H:i:s'), 'e' => $emailId, 'o' => $officeId]);
        $this->audit->log('inbox.assign');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $newClient ? 'Levél partnerhez rendelve.' : 'Partner-hozzárendelés törölve.'];

        return $this->back($response);
    }

    /** Melléklet mentése a levélhez rendelt partner dokumentumaihoz. */
    public function saveAttachment(Request $request, Response $response, array $args): Response
    {
        $officeId = (int) ($this->auth->officeId() ?? 0);
        $attId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            'SELECT a.*, e.client_id AS email_client_id
             FROM incoming_email_attachments a
             JOIN incoming_emails e ON e.id = a.email_id AND e.office_id = a.office_id
             WHERE a.id = :a AND a.office_id = :o LIMIT 1'
        );
        $stmt->execute(['a' => $attId, 'o' => $officeId]);
        $att = $stmt->fetch();

        if ($att === false) {
            return $this->back($response);
        }
        if ($att['saved_document_id']) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'A melléklet már mentve van a partnerhez.'];

            return $this->back($response);
        }
        $clientId = (int) ($att['email_client_id'] ?? 0);
        if ($clientId <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Előbb rendeld a levelet egy partnerhez, majd mentsd a mellékletet.'];

            return $this->back($response);
        }

        $userId = (int) ($this->auth->user()['id'] ?? 0) ?: null;
        $now = date('Y-m-d H:i:s');
        $ins = $this->pdo->prepare(
            "INSERT INTO documents (office_id, client_id, type, original_name, stored_path, mime, size_bytes, visibility, uploaded_by, created_at, updated_at)
             VALUES (:o, :c, 'email_attachment', :n, :p, :m, :s, 'agent_only', :u, :ca, :ua)"
        );
        $ins->execute([
            'o' => $officeId, 'c' => $clientId, 'n' => $att['filename'], 'p' => $att['stored_path'],
            'm' => $att['mime'], 's' => $att['size_bytes'], 'u' => $userId, 'ca' => $now, 'ua' => $now,
        ]);
        $docId = (int) $this->pdo->lastInsertId();

        $upd = $this->pdo->prepare('UPDATE incoming_email_attachments SET saved_document_id = :d, updated_at = :u WHERE id = :a AND office_id = :o');
        $upd->execute(['d' => $docId, 'u' => $now, 'a' => $attId, 'o' => $officeId]);

        $this->audit->log('inbox.attachment.save');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Melléklet mentve a partner dokumentumaihoz — az AI-kinyerés futtatható rá.'];

        return $this->back($response);
    }

    private function back(Response $response): Response
    {
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
