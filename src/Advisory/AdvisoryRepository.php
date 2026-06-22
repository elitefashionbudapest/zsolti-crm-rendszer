<?php

declare(strict_types=1);

namespace App\Advisory;

use App\Database\Repository;

/**
 * Tanácsadói anyagok adat-rétege, tenant-tudatosan (office_id).
 * A client_id mező null értéke azt jelenti: az iroda minden ügyfelének szól.
 */
final class AdvisoryRepository extends Repository
{
    protected function table(): string
    {
        return 'advisory_resources';
    }

    /**
     * Az iroda összes anyaga, legújabb elöl.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array
    {
        return $this->tenantSelect([], 'created_at DESC');
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

    /**
     * A portálon megjeleníthető (publikált) anyagok egy adott ügyfélnek:
     * a kifejezetten neki szólók és a mindenkinek szólók együtt.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM advisory_resources
             WHERE office_id = :office
               AND is_published = 1
               AND (client_id = :client OR client_id IS NULL)
             ORDER BY created_at DESC'
        );
        $stmt->execute([
            'office' => $this->tenant->requireOfficeId(),
            'client' => $clientId,
        ]);

        return $stmt->fetchAll();
    }
}
