<?php

declare(strict_types=1);

namespace App\Leads;

use App\Database\Repository;

/**
 * Leadek (értékesítési pipeline) adat-rétege, tenant-tudatosan (office_id).
 */
final class LeadRepository extends Repository
{
    protected function table(): string
    {
        return 'leads';
    }

    /**
     * Kereshető, fázis szerint szűrhető, lapozható lista.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(string $search = '', string $stage = '', int $page = 1, int $perPage = 20): array
    {
        $office = $this->tenant->requireOfficeId();
        $where = ['office_id = :office'];
        $params = ['office' => $office];

        if ($search !== '') {
            $where[] = '(name LIKE :s OR email LIKE :s OR phone LIKE :s)';
            $params['s'] = '%' . $search . '%';
        }
        if ($stage !== '') {
            $where[] = 'stage = :stage';
            $params['stage'] = $stage;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM leads WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM leads WHERE $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset"
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
}
