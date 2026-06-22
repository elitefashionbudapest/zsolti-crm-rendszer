<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Database\Repository;

/**
 * Feladatok adat-rétege, tenant-tudatosan (office_id).
 */
final class TaskRepository extends Repository
{
    protected function table(): string
    {
        return 'tasks';
    }

    /**
     * Kereshető, szűrhető, lapozható lista.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(string $search = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $office = $this->tenant->requireOfficeId();
        $where = ['t.office_id = :office'];
        $params = ['office' => $office];

        if ($search !== '') {
            $where[] = '(t.title LIKE :s OR t.description LIKE :s)';
            $params['s'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM tasks t WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT t.*, c.name AS client_name
             FROM tasks t
             LEFT JOIN clients c ON c.id = t.client_id AND c.office_id = t.office_id
             WHERE $whereSql
             ORDER BY t.status ASC,
                      CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END ASC,
                      t.due_at IS NULL ASC, t.due_at ASC, t.id DESC
             LIMIT $perPage OFFSET $offset"
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
        $stmt = $this->pdo->prepare(
            'SELECT t.*, c.name AS client_name
             FROM tasks t
             LEFT JOIN clients c ON c.id = t.client_id AND c.office_id = t.office_id
             WHERE t.id = :id AND t.office_id = :office'
        );
        $stmt->execute(['id' => $id, 'office' => $this->tenant->requireOfficeId()]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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

    /** Státusz váltása: open <-> done. */
    public function toggleStatus(int $id): bool
    {
        $task = $this->tenantFind($id);
        if ($task === null) {
            return false;
        }

        $next = ($task['status'] ?? 'open') === 'done' ? 'open' : 'done';

        return $this->tenantUpdate($id, [
            'status' => $next,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Az iroda partnerei a legördülő választóhoz.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function clientsForOffice(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM clients WHERE office_id = :office ORDER BY name ASC'
        );
        $stmt->execute(['office' => $this->tenant->requireOfficeId()]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $out;
    }
}
