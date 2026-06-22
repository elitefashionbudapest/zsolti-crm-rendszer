<?php

declare(strict_types=1);

namespace Tests;

use App\Clients\ClientRepository;
use App\Tenant\TenantContext;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A legfontosabb biztonsági tulajdonság: egy iroda soha nem látja más iroda
 * adatát. A repository minden lekérdezésnél office_id-re szűr.
 */
final class TenantIsolationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                office_id INTEGER, owner_user_id INTEGER, name TEXT, email TEXT,
                phone TEXT, mobile TEXT, address TEXT, tax_id TEXT, birth_date TEXT,
                birth_place TEXT, mother_name TEXT, notes TEXT, status TEXT,
                created_at TEXT, updated_at TEXT
            )'
        );
        $this->pdo->exec("INSERT INTO clients (office_id, name, status) VALUES (1, 'Iroda 1 ügyfél', 'active')");
        $this->pdo->exec("INSERT INTO clients (office_id, name, status) VALUES (2, 'Iroda 2 ügyfél', 'active')");
    }

    public function testListOnlyReturnsOwnOfficeClients(): void
    {
        $tenant = new TenantContext();
        $tenant->set(1);
        $repo = new ClientRepository($this->pdo, $tenant);

        $result = $repo->paginate();

        self::assertSame(1, $result['total']);
        self::assertSame('Iroda 1 ügyfél', $result['rows'][0]['name']);
    }

    public function testFindCannotReachOtherOfficeRecord(): void
    {
        $tenant = new TenantContext();
        $tenant->set(1);
        $repo = new ClientRepository($this->pdo, $tenant);

        // A 2-es id a 2-es irodáé — az 1-es iroda nem érheti el (IDOR-védelem).
        self::assertNull($repo->find(2));
        self::assertNotNull($repo->find(1));
    }

    public function testInsertForcesOwnOfficeId(): void
    {
        $tenant = new TenantContext();
        $tenant->set(1);
        $repo = new ClientRepository($this->pdo, $tenant);

        $id = $repo->create(['name' => 'Új ügyfél', 'status' => 'active']);
        $row = $repo->find($id);

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['office_id']);
    }
}
