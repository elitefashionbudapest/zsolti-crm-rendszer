<?php

declare(strict_types=1);

namespace App\Clients;

use App\Database\Repository;

/**
 * A partnerhez kötött, címezhető kulcs-érték adatok (AI-kinyerésből vagy kézzel).
 * Tenant-tudatos (office_id). Egy sor = egy mező, hogy másik dokumentum (sablon)
 * is kitölthető legyen belőle a TemplateFiller lapos [kulcs => érték] térképén át.
 */
final class ClientAttributeRepository extends Repository
{
    protected function table(): string
    {
        return 'client_attributes';
    }

    /**
     * Egy partner összes attribútuma, csoport majd felirat szerint rendezve.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM client_attributes WHERE client_id = :c AND office_id = :o
             ORDER BY attr_group ASC, label ASC, id ASC'
        );
        $stmt->execute(['c' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    /** Van-e már bármilyen attribútuma a partnernek (a felülírás-kérdéshez). */
    public function hasAnyForClient(int $clientId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM client_attributes WHERE client_id = :c AND office_id = :o LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetch() !== false;
    }

    /**
     * Egy partner attribútumainak teljes cseréje (felülírás): a régiek törlése,
     * majd az új sorok beszúrása — egy tranzakcióban.
     *
     * @param array<int,array{group?:string,attr_key:string,label?:string,value?:string,contract_id?:int|null}> $rows
     */
    public function replaceForClient(int $clientId, array $rows, ?int $extractionId = null): void
    {
        $office = $this->tenant->requireOfficeId();
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM client_attributes WHERE client_id = :c AND office_id = :o');
            $del->execute(['c' => $clientId, 'o' => $office]);

            $this->insertRows($clientId, $rows, $extractionId, $office);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Attribútumok hozzáadása felülírás nélkül (csak az új kulcsok szúródnak be;
     * a meglévők maradnak — a nem-null contract_id-re az egyedi index véd).
     *
     * @param array<int,array{group?:string,attr_key:string,label?:string,value?:string,contract_id?:int|null}> $rows
     */
    public function addMissingForClient(int $clientId, array $rows, ?int $extractionId = null): void
    {
        $office = $this->tenant->requireOfficeId();

        // A már meglévő (group, key, contract_id) hármasok, hogy ne duplikáljunk.
        $existing = [];
        foreach ($this->forClient($clientId) as $r) {
            $existing[$this->rowKey((string) $r['attr_group'], (string) $r['attr_key'], $r['contract_id'] === null ? null : (int) $r['contract_id'])] = true;
        }

        $fresh = [];
        foreach ($rows as $row) {
            $group = (string) ($row['group'] ?? 'egyeb');
            $key = trim((string) $row['attr_key']);
            $cid = $row['contract_id'] ?? null;
            if ($key === '' || isset($existing[$this->rowKey($group, $key, $cid === null ? null : (int) $cid)])) {
                continue;
            }
            $fresh[] = $row;
        }

        if ($fresh !== []) {
            $this->insertRows($clientId, $fresh, $extractionId, $office);
        }
    }

    /** Egy attribútum feliratának/értékének frissítése (partner-adatlapról). */
    public function updateOne(int $id, string $label, string $value): bool
    {
        return $this->tenantUpdate($id, [
            'label' => $label,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Új, kézi attribútum a partnerhez (partner-adatlapról). */
    public function addManual(int $clientId, string $group, string $key, string $label, string $value): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->tenantInsert([
            'client_id' => $clientId,
            'contract_id' => null,
            'extraction_id' => null,
            'attr_group' => $group !== '' ? $group : 'egyeb',
            'attr_key' => $key,
            'label' => $label,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->tenantDelete($id);
    }

    /**
     * @param array<int,array{group?:string,attr_key:string,label?:string,value?:string,contract_id?:int|null}> $rows
     */
    private function insertRows(int $clientId, array $rows, ?int $extractionId, int $office): void
    {
        $now = date('Y-m-d H:i:s');
        $ins = $this->pdo->prepare(
            'INSERT INTO client_attributes
                (office_id, client_id, contract_id, extraction_id, attr_group, attr_key, label, value, created_at, updated_at)
             VALUES (:o, :c, :ct, :e, :g, :k, :l, :v, :ca, :ua)'
        );
        foreach ($rows as $row) {
            $key = trim((string) $row['attr_key']);
            if ($key === '') {
                continue;
            }
            $ins->execute([
                'o' => $office,
                'c' => $clientId,
                'ct' => $row['contract_id'] ?? null,
                'e' => $extractionId,
                'g' => (string) ($row['group'] ?? 'egyeb'),
                'k' => $key,
                'l' => (string) ($row['label'] ?? $key),
                'v' => (string) ($row['value'] ?? ''),
                'ca' => $now,
                'ua' => $now,
            ]);
        }
    }

    private function rowKey(string $group, string $key, ?int $contractId): string
    {
        return $group . '|' . $key . '|' . ($contractId ?? 'null');
    }
}
