<?php

declare(strict_types=1);

namespace App\Email;

use App\Database\Repository;

/**
 * E-mail folyamatok (automatizmusok) adat-rétege, tenant-tudatosan (office_id).
 */
final class EmailWorkflowRepository extends Repository
{
    protected function table(): string
    {
        return 'email_workflows';
    }

    /** @return array<int,array<string,mixed>> */
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
}
