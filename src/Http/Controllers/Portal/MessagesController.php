<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ügyfélportál: üzenetküldés az ügynöknek. Az üzenet feladatként (task) kerül
 * az ügynökhöz, és a korábbi üzenetek is itt jelennek meg.
 */
final class MessagesController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $officeId = $this->auth->officeId();
        $client = $this->client($clientId, $officeId);

        return $this->twig->render($response, 'portal/messages.twig', [
            'active' => 'messages',
            'agent_name' => $this->agentName($client, $officeId),
            'messages' => $this->previousMessages($clientId, $officeId),
            'flash' => $this->flash(),
        ]);
    }

    public function send(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $officeId = $this->auth->officeId();
        $client = $this->client($clientId, $officeId);

        $body = (array) $request->getParsedBody();
        $message = trim((string) ($body['message'] ?? ''));

        if ($message !== '' && $clientId !== null && $officeId !== null && $client !== null) {
            $clientName = trim((string) ($client['name'] ?? '')) ?: 'ügyfél';
            $assignedTo = isset($client['owner_user_id']) && $client['owner_user_id'] !== null
                ? (int) $client['owner_user_id']
                : null;

            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO tasks '
                . '(office_id, client_id, assigned_to, title, description, due_at, status, priority, created_at, updated_at) '
                . 'VALUES (:office_id, :client_id, :assigned_to, :title, :description, :due_at, :status, :priority, :created_at, :updated_at)'
            );
            $stmt->execute([
                'office_id' => $officeId,
                'client_id' => $clientId,
                'assigned_to' => $assignedTo,
                'title' => 'Ügyfél üzenet: ' . $clientName,
                'description' => $message,
                'due_at' => null,
                'status' => 'open',
                'priority' => 'normal',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Üzenetedet elküldtük ügynöködnek.'];
        }

        return $response->withHeader('Location', '/portal/uzenetek')->withStatus(302);
    }

    /**
     * A belépett ügyfél sora (owner_user_id-vel együtt).
     *
     * @return array<string,mixed>|null
     */
    private function client(?int $clientId, ?int $officeId): ?array
    {
        if ($clientId === null || $officeId === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, name, owner_user_id FROM clients WHERE id = :id AND office_id = :office_id LIMIT 1'
        );
        $stmt->execute(['id' => $clientId, 'office_id' => $officeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed>|null $client */
    private function agentName(?array $client, ?int $officeId): string
    {
        $ownerId = $client !== null && isset($client['owner_user_id']) && $client['owner_user_id'] !== null
            ? (int) $client['owner_user_id']
            : null;

        if ($ownerId === null || $officeId === null) {
            return 'Ügynököd';
        }

        $stmt = $this->pdo->prepare(
            'SELECT name FROM users WHERE id = :id AND office_id = :office_id LIMIT 1'
        );
        $stmt->execute(['id' => $ownerId, 'office_id' => $officeId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && trim($name) !== '' ? $name : 'Ügynököd';
    }

    /**
     * A korábban beküldött üzenetek (a portálról érkezett feladatok).
     *
     * @return array<int,array<string,mixed>>
     */
    private function previousMessages(?int $clientId, ?int $officeId): array
    {
        if ($clientId === null || $officeId === null) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT description, created_at FROM tasks '
            . 'WHERE client_id = :client_id AND office_id = :office_id AND title LIKE :title '
            . 'ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'office_id' => $officeId,
            'title' => 'Ügyfél üzenet:%',
        ]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }
}
