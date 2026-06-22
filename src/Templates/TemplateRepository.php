<?php

declare(strict_types=1);

namespace App\Templates;

use App\Database\Repository;

/**
 * Dokumentumsablonok adat-rétege, tenant-tudatosan (office_id).
 *
 * A sablon lehet PDF rátét ('overlay') vagy Word-sablon ('docx'). A field_map
 * mező nyers JSON-ként tárolja a kitöltési szabályokat (lásd TemplateFiller).
 */
final class TemplateRepository extends Repository
{
    protected function table(): string
    {
        return 'document_templates';
    }

    /**
     * Az aktuális iroda összes sablonja, legújabb elöl.
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
}
