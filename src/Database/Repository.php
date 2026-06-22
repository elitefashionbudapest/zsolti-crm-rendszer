<?php

declare(strict_types=1);

namespace App\Database;

use App\Tenant\TenantContext;
use PDO;

/**
 * Tenant-tudatos alap-repository. A táblát kezelő leszármazottak ezen keresztül
 * érik el az adatbázist; a tenantScoped() metódus kötelezően office_id-re szűr,
 * így egy iroda soha nem látja más iroda adatát.
 */
abstract class Repository
{
    public function __construct(
        protected PDO $pdo,
        protected TenantContext $tenant,
    ) {
    }

    /** A tábla neve (a leszármazott adja meg). */
    abstract protected function table(): string;

    /**
     * Tenant-szűrt lekérdezés: a megadott WHERE-hez hozzáfűzi az office_id szűrőt.
     *
     * @param array<string,mixed> $where
     * @return array<int,array<string,mixed>>
     */
    protected function tenantSelect(array $where = [], string $orderBy = 'id DESC', ?int $limit = null): array
    {
        $clauses = ['office_id = :__office'];
        $params = ['__office' => $this->tenant->requireOfficeId()];

        foreach ($where as $col => $val) {
            $clauses[] = sprintf('%s = :%s', $col, $col);
            $params[$col] = $val;
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY %s',
            $this->table(),
            implode(' AND ', $clauses),
            $orderBy,
        );
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Egy rekord lekérése id alapján, tenant-ellenőrzéssel (IDOR-védelem). */
    protected function tenantFind(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            sprintf('SELECT * FROM %s WHERE id = :id AND office_id = :office', $this->table())
        );
        $stmt->execute(['id' => $id, 'office' => $this->tenant->requireOfficeId()]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Beszúrás az aktuális irodához, automatikus office_id-vel.
     *
     * @param array<string,mixed> $data
     */
    protected function tenantInsert(array $data): int
    {
        $data['office_id'] = $this->tenant->requireOfficeId();
        $cols = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $cols);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table(),
            implode(', ', $cols),
            implode(', ', $placeholders),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Frissítés id alapján, tenant-ellenőrzéssel.
     *
     * @param array<string,mixed> $data
     */
    protected function tenantUpdate(int $id, array $data): bool
    {
        unset($data['id'], $data['office_id']);
        $sets = array_map(static fn (string $c): string => sprintf('%s = :%s', $c, $c), array_keys($data));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :__id AND office_id = :__office',
            $this->table(),
            implode(', ', $sets),
        );
        $stmt = $this->pdo->prepare($sql);
        $params = $data;
        $params['__id'] = $id;
        $params['__office'] = $this->tenant->requireOfficeId();

        return $stmt->execute($params);
    }

    /** Törlés id alapján, tenant-ellenőrzéssel. */
    protected function tenantDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE id = :id AND office_id = :office', $this->table())
        );

        return $stmt->execute(['id' => $id, 'office' => $this->tenant->requireOfficeId()]);
    }
}
