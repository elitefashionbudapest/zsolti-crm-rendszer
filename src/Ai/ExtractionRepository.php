<?php

declare(strict_types=1);

namespace App\Ai;

use App\Database\Repository;

/**
 * A Claude-alapú adatkinyerés eredményeinek adat-rétege, tenant-tudatosan (office_id).
 */
final class ExtractionRepository extends Repository
{
    protected function table(): string
    {
        return 'extracted_data';
    }

    /**
     * A jóváhagyásra váró (pending) kinyerések listája, a legújabb elöl.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pending(): array
    {
        return $this->tenantSelect(['status' => 'pending'], 'created_at DESC');
    }

    /** Egy rekord lekérése id alapján, tenant-ellenőrzéssel. */
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

    /** A státusz frissítése (pending|approved|rejected). */
    public function updateStatus(int $id, string $status): bool
    {
        return $this->tenantUpdate($id, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A jóváhagyott rekordhoz a létrejött partner hozzákötése. */
    public function attachClient(int $id, int $clientId): bool
    {
        return $this->tenantUpdate($id, [
            'client_id' => $clientId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A szerkesztett mezők (JSON string) elmentése. */
    public function setFields(int $id, string $jsonString): bool
    {
        return $this->tenantUpdate($id, [
            'fields' => $jsonString,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->tenantDelete($id);
    }
}
