<?php

declare(strict_types=1);

namespace App\Documents;

use App\Database\Repository;

/**
 * Dokumentumok adat-rétege, tenant-tudatosan (office_id).
 */
final class DocumentRepository extends Repository
{
    protected function table(): string
    {
        return 'documents';
    }

    /**
     * Kereshető, ügyfél szerint szűrhető, lapozható lista (ügynöki oldal).
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(string $search = '', ?int $clientId = null, int $page = 1, int $perPage = 20): array
    {
        $office = $this->tenant->requireOfficeId();
        $where = ['d.office_id = :office'];
        $params = ['office' => $office];

        if ($search !== '') {
            $where[] = 'd.original_name LIKE :s';
            $params['s'] = '%' . $search . '%';
        }
        if ($clientId !== null) {
            $where[] = 'd.client_id = :client';
            $params['client'] = $clientId;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM documents d WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT d.*, c.name AS client_name
             FROM documents d
             LEFT JOIN clients c ON c.id = d.client_id AND c.office_id = d.office_id
             WHERE $whereSql
             ORDER BY d.created_at DESC, d.id DESC
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
        return $this->tenantFind($id);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->tenantInsert($data);
    }

    public function delete(int $id): bool
    {
        return $this->tenantDelete($id);
    }

    /**
     * Az iroda ügyfelei a feltöltési legördülőhöz.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function clientsForOffice(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM clients WHERE office_id = :o ORDER BY name ASC'
        );
        $stmt->execute(['o' => $this->tenant->requireOfficeId()]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $rows;
    }

    /**
     * Egy ügyfél megosztott dokumentumai az ügyfélportálhoz.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forClientShared(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM documents
             WHERE client_id = :c AND office_id = :o AND visibility = 'shared'
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute(['c' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }
}
