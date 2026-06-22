<?php

declare(strict_types=1);

namespace App\Clients;

use App\Database\Repository;

/**
 * Partnerek (ügyfelek) adat-rétege, tenant-tudatosan (office_id).
 */
final class ClientRepository extends Repository
{
    protected function table(): string
    {
        return 'clients';
    }

    /**
     * Kereshető, szűrhető, lapozható lista.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(string $search = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $office = $this->tenant->requireOfficeId();
        $where = ['office_id = :office'];
        $params = ['office' => $office];

        if ($search !== '') {
            $where[] = '(name LIKE :s OR email LIKE :s OR phone LIKE :s OR mobile LIKE :s)';
            $params['s'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM clients WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM clients WHERE $whereSql ORDER BY name ASC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'perPage' => $perPage,
        ];
    }

    public function find(int $id): ?array
    {
        return $this->tenantFind($id);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->tenantInsert($data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->tenantUpdate($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->tenantDelete($id);
    }

    /** A szerződések száma partnerenként (a listához). */
    public function contractCount(int $clientId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM contracts WHERE client_id = :id AND office_id = :o');
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    /**
     * Az ügyfél szerződései (az adatlaphoz), tenant-szűrve.
     *
     * @return array<int,array<string,mixed>>
     */
    public function contractsFor(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, category, insurer_name, module_name, policy_number, start_date, end_date, anniversary, annual_fee, status
             FROM contracts WHERE client_id = :id AND office_id = :o ORDER BY id DESC'
        );
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    /** Az ügyfél dokumentumai (adatlap). @return array<int,array<string,mixed>> */
    public function documentsFor(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, original_name, type, visibility, mime, size_bytes, created_at
             FROM documents WHERE client_id = :id AND office_id = :o ORDER BY id DESC'
        );
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    /** Az ügyfélhez kötött feladatok (adatlap). @return array<int,array<string,mixed>> */
    public function tasksFor(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, due_at, status, priority FROM tasks
             WHERE client_id = :id AND office_id = :o
             ORDER BY (status = 'done'), due_at ASC"
        );
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    /** Az ügyfél által beküldött (portál) adatlapok. @return array<int,array<string,mixed>> */
    public function intakeFor(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, payload, created_at FROM client_intake_submissions
             WHERE client_id = :id AND office_id = :o ORDER BY id DESC'
        );
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    /** Belső megjegyzések a partnerhez (szerzővel). @return array<int,array<string,mixed>> */
    public function notesFor(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id, n.body, n.created_at, u.name AS author
             FROM client_notes n LEFT JOIN users u ON u.id = n.user_id
             WHERE n.client_id = :id AND n.office_id = :o ORDER BY n.id DESC'
        );
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    public function addNote(int $clientId, ?int $userId, string $body): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO client_notes (office_id, client_id, user_id, body, created_at) VALUES (:o, :c, :u, :b, :ts)'
        );
        $stmt->execute([
            'o' => $this->tenant->requireOfficeId(), 'c' => $clientId, 'u' => $userId,
            'b' => $body, 'ts' => date('Y-m-d H:i:s'),
        ]);
    }

    /** E-mail kiküldés naplózása (a partnernek küldött üzenethez). */
    public function logEmail(string $to, string $subject, string $status, ?string $error): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_sends (office_id, to_email, subject, status, error, sent_at, created_at, updated_at)
             VALUES (:o, :t, :s, :st, :e, :sa, :c, :u)'
        );
        $stmt->execute([
            'o' => $this->tenant->requireOfficeId(), 't' => $to, 's' => $subject, 'st' => $status,
            'e' => $error, 'sa' => $status === 'sent' ? $now : null, 'c' => $now, 'u' => $now,
        ]);
    }
}
