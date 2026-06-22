<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * A belépett felhasználóhoz tartozó iroda (tenant) azonosítója a kérés idejére.
 * A repository-réteg ebből kötelezően office_id-re szűr.
 */
final class TenantContext
{
    private ?int $officeId = null;
    private bool $superAdmin = false;

    public function set(?int $officeId, bool $superAdmin = false): void
    {
        $this->officeId = $officeId;
        $this->superAdmin = $superAdmin;
    }

    public function officeId(): ?int
    {
        return $this->officeId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    /** A tenant-szűréshez kötelező office_id; hiba, ha hiányzik (kivéve szuperadmin). */
    public function requireOfficeId(): int
    {
        if ($this->officeId === null) {
            throw new \RuntimeException('Nincs iroda-kontextus (tenant).');
        }

        return $this->officeId;
    }
}
