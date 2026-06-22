<?php

declare(strict_types=1);

namespace App\Commissions;

use App\Database\Repository;

/**
 * Jutalékok adat-rétege, tenant-tudatosan (office_id).
 */
final class CommissionRepository extends Repository
{
    protected function table(): string
    {
        return 'commissions';
    }

    /**
     * Státusz szerint szűrhető, lapozható lista.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(string $status = '', int $page = 1, int $perPage = 20): array
    {
        $office = $this->tenant->requireOfficeId();
        $where = ['c.office_id = :office'];
        $params = ['office' => $office];

        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM commissions c WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT c.*, COALESCE(NULLIF(ct.policy_number, ''), NULLIF(ct.module_name, '')) AS contract_label
             FROM commissions c
             LEFT JOIN contracts ct ON ct.id = c.contract_id AND ct.office_id = c.office_id
             WHERE $whereSql
             ORDER BY c.created_at DESC, c.id DESC
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

    /** A jutalékot rendezettre állítja, a mai dátummal. */
    public function markSettled(int $id): bool
    {
        return $this->update($id, [
            'status' => 'settled',
            'settled_at' => date('Y-m-d'),
        ]);
    }

    /**
     * Függő és rendezett jutalékok összege (tenant-szűrt).
     *
     * @return array{pending: float, settled: float}
     */
    public function totals(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'settled' THEN amount ELSE 0 END), 0) AS settled
             FROM commissions WHERE office_id = :office"
        );
        $stmt->execute(['office' => $this->tenant->requireOfficeId()]);
        $row = $stmt->fetch() ?: [];

        return [
            'pending' => (float) ($row['pending'] ?? 0),
            'settled' => (float) ($row['settled'] ?? 0),
        ];
    }

    /**
     * A szerződések listája a legördülőhöz: [id, label], ahol a címke a kötvényszám,
     * vagy ha az nincs, a modul neve.
     *
     * @return array<int,array{id: int, label: string}>
     */
    public function contractsForOffice(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, policy_number, module_name
             FROM contracts
             WHERE office_id = :office
             ORDER BY COALESCE(NULLIF(policy_number, ''), NULLIF(module_name, ''), CAST(id AS CHAR)) ASC"
        );
        $stmt->execute(['office' => $this->tenant->requireOfficeId()]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $label = (string) ($row['policy_number'] ?? '');
            if ($label === '') {
                $label = (string) ($row['module_name'] ?? '');
            }
            if ($label === '') {
                $label = '#' . $row['id'];
            }
            $out[] = ['id' => (int) $row['id'], 'label' => $label];
        }

        return $out;
    }
}
