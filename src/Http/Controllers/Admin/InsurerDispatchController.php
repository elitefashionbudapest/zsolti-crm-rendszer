<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Documents\DocumentStorage;
use App\Insurers\InsurerRouteRepository;
use App\Mail\MailService;
use App\Support\AuditLogger;
use PDO;
use Slim\Views\Twig;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Biztosítói küldés: egy szerződéshez tartozó megosztott dokumentumok elküldése a
 * biztosító (kategória szerint feloldott) címlistájára, naplózással.
 */
final class InsurerDispatchController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private InsurerRouteRepository $routes,
        private MailService $mail,
        private DocumentStorage $storage,
        private AuditLogger $audit,
        private PDO $pdo,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $officeId = (int) $this->auth->officeId();

        return $this->twig->render($response, 'admin/email/dispatch.twig', [
            'active' => 'emails',
            'contracts' => $this->contractOptions($officeId),
            'dispatches' => $this->recentDispatches($officeId),
            'flash' => $this->flash(),
        ]);
    }

    public function send(Request $request, Response $response): Response
    {
        $officeId = (int) $this->auth->officeId();
        $body = (array) $request->getParsedBody();
        $contractId = (int) ($body['contract_id'] ?? 0);

        $contract = $this->findContract($officeId, $contractId);
        if ($contract === null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Érvénytelen szerződés.'];
            return $this->redirect($response, '/admin/biztositoi-kuldes');
        }

        $insurerId = $contract['insurer_id'] !== null ? (int) $contract['insurer_id'] : null;
        if ($insurerId === null) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A szerződéshez nincs biztosító rendelve, ezért nem lehet címzettet feloldani.'];
            return $this->redirect($response, '/admin/biztositoi-kuldes');
        }

        $category = $contract['category'] !== null ? (string) $contract['category'] : null;
        $recipients = $this->routes->resolveRecipients($insurerId, $category);

        if ($recipients === []) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nincs feloldható címzett ehhez a biztosítóhoz, így a küldés nem indult el.'];
            return $this->redirect($response, '/admin/biztositoi-kuldes');
        }

        if (!$this->mail->isConfigured($officeId)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Az iroda SMTP-beállításai hiányoznak, ezért a küldés nem indult el. Töltsd ki a Beállításokban.'];
            return $this->redirect($response, '/admin/biztositoi-kuldes');
        }

        $documents = $this->sharedDocuments($officeId, $contractId);
        $documentIds = [];
        $attachmentPaths = [];
        foreach ($documents as $doc) {
            $documentIds[] = (int) $doc['id'];
            try {
                $path = $this->storage->fullPath((string) $doc['stored_path']);
                if (is_file($path)) {
                    $attachmentPaths[] = $path;
                }
            } catch (Throwable) {
                // Érvénytelen vagy hiányzó fájl: kihagyjuk, a küldés folytatódik.
            }
        }

        $policyNumber = (string) ($contract['policy_number'] ?? '') ?: ('#' . $contractId);
        $subject = 'Dokumentumok – ' . $policyNumber;
        $bodyHtml = sprintf(
            '<p>Tisztelt Címzett!</p><p>Csatoltan küldjük a(z) <strong>%s</strong> kötvényszámú szerződéshez tartozó dokumentumokat.</p><p>Üdvözlettel,<br>Aegis CRM</p>',
            htmlspecialchars($policyNumber, ENT_QUOTES, 'UTF-8'),
        );

        $status = 'sent';
        $error = null;
        try {
            $this->mail->send($officeId, $recipients, $subject, $bodyHtml, $attachmentPaths);
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO insurer_dispatches
                (office_id, contract_id, insurer_id, recipients, document_ids, status, error, sent_at, created_at, updated_at)
             VALUES (:o, :c, :i, :r, :d, :s, :e, :sent, :ca, :ua)'
        );
        $stmt->execute([
            'o' => $officeId,
            'c' => $contractId,
            'i' => $insurerId,
            'r' => implode(',', $recipients),
            'd' => implode(',', $documentIds),
            's' => $status,
            'e' => $error,
            'sent' => $status === 'sent' ? $now : null,
            'ca' => $now,
            'ua' => $now,
        ]);

        $this->audit->log('insurer.dispatch', 'contract', $contractId);

        if ($status === 'sent') {
            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' => sprintf('Elküldve %d címzettnek, %d csatolt dokumentummal.', count($recipients), count($attachmentPaths)),
            ];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A küldés sikertelen: ' . (string) $error];
        }

        return $this->redirect($response, '/admin/biztositoi-kuldes');
    }

    /**
     * Az iroda szerződései a választóhoz (id + kötvényszám/módozat).
     *
     * @return array<int,array<string,mixed>>
     */
    private function contractOptions(int $officeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, policy_number, module_name FROM contracts WHERE office_id = :o ORDER BY id DESC'
        );
        $stmt->execute(['o' => $officeId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    private function findContract(int $officeId, int $contractId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, insurer_id, category, policy_number, module_name FROM contracts WHERE id = :id AND office_id = :o LIMIT 1'
        );
        $stmt->execute(['id' => $contractId, 'o' => $officeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * A szerződéshez tartozó megosztott (ügyféllel is megosztott) dokumentumok.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sharedDocuments(int $officeId, int $contractId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, stored_path, original_name FROM documents
             WHERE contract_id = :c AND office_id = :o AND visibility = 'shared'"
        );
        $stmt->execute(['c' => $contractId, 'o' => $officeId]);

        return $stmt->fetchAll();
    }

    /**
     * A legutóbbi küldések a naplólistához.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentDispatches(int $officeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM insurer_dispatches WHERE office_id = :o ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute(['o' => $officeId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
