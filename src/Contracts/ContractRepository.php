<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Database\Repository;

/**
 * Szerződések adat-rétege, tenant-tudatosan (office_id).
 */
final class ContractRepository extends Repository
{
    protected function table(): string
    {
        return 'contracts';
    }

    /**
     * Kereshető, szűrhető, lapozható lista. A keresés a kötvényszámra, a
     * módozat nevére és a biztosító nevére fut; az ügyfél neve allekérdezésből jön.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function paginate(string $search = '', string $category = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $office = $this->tenant->requireOfficeId();
        $where = ['c.office_id = :office'];
        $params = ['office' => $office];

        if ($search !== '') {
            $where[] = '(c.policy_number LIKE :s OR c.module_name LIKE :s OR c.insurer_name LIKE :s)';
            $params['s'] = '%' . $search . '%';
        }
        if ($category !== '') {
            $where[] = 'c.category = :category';
            $params['category'] = $category;
        }
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM contracts c WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT c.*, (SELECT cl.name FROM clients cl WHERE cl.id = c.client_id) AS client_name
             FROM contracts c
             WHERE $whereSql
             ORDER BY c.created_at DESC
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

    /** Egy szerződés az ügyfél nevével együtt (a megtekintéshez). */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, (SELECT cl.name FROM clients cl WHERE cl.id = c.client_id) AS client_name
             FROM contracts c
             WHERE c.id = :id AND c.office_id = :office'
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

    /**
     * Az aktuális iroda partnerei a szerződés-űrlap legördülőjéhez.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function clientsForOffice(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM clients WHERE office_id = :o ORDER BY name ASC');
        $stmt->execute(['o' => $this->tenant->requireOfficeId()]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $out;
    }

    /** Ellenőrzi, hogy a partner az aktuális irodához tartozik-e (IDOR-védelem). */
    public function clientBelongsToOffice(int $clientId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM clients WHERE id = :id AND office_id = :o');
        $stmt->execute(['id' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetch() !== false;
    }
}
