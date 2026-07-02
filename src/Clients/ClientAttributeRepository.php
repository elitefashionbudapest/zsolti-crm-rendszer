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
     * Egy partner ÖSSZES attribútuma (partner- és szerződés-szintű együtt),
     * csoport majd felirat szerint rendezve. A sablon-kitöltéshez (ClientDataMap)
     * kell, hogy minden adat elérhető legyen.
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

    /**
     * A partner SZEMÉLYI (szerződéshez nem kötött) attribútumai — ezek jelennek
     * meg a partner-adatlapon (a kiemelt adatok).
     *
     * @return array<int,array<string,mixed>>
     */
    public function personalForClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM client_attributes
             WHERE client_id = :c AND office_id = :o AND contract_id IS NULL
             ORDER BY attr_group ASC, label ASC, id ASC'
        );
        $stmt->execute(['c' => $clientId, 'o' => $this->tenant->requireOfficeId()]);

        return $stmt->fetchAll();
    }

    /**
     * Egy szerződéshez kötött attribútumok — a szerződés-adatlapon jelennek meg.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forContract(int $contractId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM client_attributes
             WHERE contract_id = :ct AND office_id = :o
             ORDER BY attr_group ASC, label ASC, id ASC'
        );
        $stmt->execute(['ct' => $contractId, 'o' => $this->tenant->requireOfficeId()]);

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
     * A partner SZEMÉLYI (szerződéshez nem kötött) attribútumainak teljes cseréje.
     * A szerződés-szintű sorokat NEM érinti.
     *
     * @param array<int,array{group?:string,attr_key:string,label?:string,value?:string,contract_id?:int|null}> $rows
     */
    public function replaceForClient(int $clientId, array $rows, ?int $extractionId = null): void
    {
        $office = $this->tenant->requireOfficeId();
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare(
                'DELETE FROM client_attributes WHERE client_id = :c AND office_id = :o AND contract_id IS NULL'
            );
            $del->execute(['c' => $clientId, 'o' => $office]);

            $this->insertRows($clientId, null, $rows, $extractionId, $office);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Egy szerződéshez kötött attribútumok teljes cseréje (felülírás).
     *
     * @param array<int,array{group?:string,attr_key:string,label?:string,value?:string,contract_id?:int|null}> $rows
     */
    public function replaceForContract(int $clientId, int $contractId, array $rows, ?int $extractionId = null): void
    {
        $office = $this->tenant->requireOfficeId();
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare(
                'DELETE FROM client_attributes WHERE contract_id = :ct AND office_id = :o'
            );
            $del->execute(['ct' => $contractId, 'o' => $office]);

            $this->insertRows($clientId, $contractId, $rows, $extractionId, $office);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Egy attribútum feliratának/értékének frissítése (adatlapról). */
    public function updateOne(int $id, string $label, string $value): bool
    {
        return $this->tenantUpdate($id, [
            'label' => $label,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Új, kézi attribútum a partnerhez (partner-adatlapról → személyi, contract_id null;
     * szerződés-adatlapról → az adott szerződéshez kötve).
     */
    public function addManual(int $clientId, string $group, string $key, string $label, string $value, ?int $contractId = null): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->tenantInsert([
            'client_id' => $clientId,
            'contract_id' => $contractId,
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
     * A sorok beszúrása a megadott szinttel: $contractId = null → partner-szintű,
     * egyébként az adott szerződéshez kötve.
     *
     * @param array<int,array{group?:string,attr_key:string,label?:string,value?:string,contract_id?:int|null}> $rows
     */
    private function insertRows(int $clientId, ?int $contractId, array $rows, ?int $extractionId, int $office): void
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
                'ct' => $contractId,
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
}
