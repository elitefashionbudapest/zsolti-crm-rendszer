<?php

declare(strict_types=1);

namespace App\Insurers;

use App\Database\Repository;

/**
 * Biztosítók e-mail címlistái (kategória szerinti útválasztás), tenant-tudatosan.
 */
final class InsurerRouteRepository extends Repository
{
    protected function table(): string
    {
        return 'insurer_email_routes';
    }

    /**
     * Egy biztosítóhoz tartozó címlisták.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forInsurer(int $insurerId): array
    {
        return $this->tenantSelect(['insurer_id' => $insurerId], 'category ASC, id ASC');
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

    public function delete(int $id): bool
    {
        return $this->tenantDelete($id);
    }

    /**
     * A megadott kategóriához tartozó címzettek feloldása.
     *
     * Először a kategóriára illeszkedő címlistát keresi, ha nincs, akkor az
     * üres (minden típusra érvényes) címlistát, végül a biztosító alapértelmezett
     * címeit használja. A talált címeket vesszők, pontosvesszők és sortörések
     * mentén bontja, levágja a felesleges szóközöket és kiszűri az érvénytelen
     * címeket.
     *
     * @return array<int,string>
     */
    public function resolveRecipients(int $insurerId, ?string $category): array
    {
        $routes = $this->forInsurer($insurerId);

        $byCategory = null;
        $byNull = null;
        foreach ($routes as $route) {
            $routeCategory = $route['category'] === null || $route['category'] === ''
                ? null
                : (string) $route['category'];

            if ($category !== null && $routeCategory === $category) {
                $byCategory = $route;
            }
            if ($routeCategory === null) {
                $byNull = $route;
            }
        }

        $source = $byCategory ?? $byNull;
        if ($source !== null) {
            $emails = self::splitEmails((string) ($source['emails'] ?? ''));
            if ($emails !== []) {
                return $emails;
            }
        }

        $insurer = $this->tenantFind($insurerId);
        if ($insurer === null) {
            return [];
        }

        return self::splitEmails((string) ($insurer['default_emails'] ?? ''));
    }

    /**
     * E-mail címek szétbontása vesszők, pontosvesszők és sortörések mentén.
     *
     * @return array<int,string>
     */
    public static function splitEmails(string $raw): array
    {
        $parts = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }
}
