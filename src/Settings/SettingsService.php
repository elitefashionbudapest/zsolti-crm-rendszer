<?php

declare(strict_types=1);

namespace App\Settings;

use App\Support\Encryption;
use PDO;

/**
 * Irodánkénti beállítások kulcs-érték tárolása. A titkos kulcsok (jelszavak,
 * API-kulcsok, tokenek) titkosítva tárolódnak (Defuse).
 */
final class SettingsService
{
    /** Ezeket a kulcsokat titkosítva tároljuk. */
    private const SECRET_KEYS = [
        'smtp_password', 'anthropic_api_key', 'imap_password',
        'google_access_token', 'google_refresh_token',
    ];

    public function __construct(
        private PDO $pdo,
        private Encryption $encryption,
    ) {
    }

    public function get(int $officeId, string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value_encrypted FROM office_settings WHERE office_id = :o AND `key` = :k LIMIT 1');
        $stmt->execute(['o' => $officeId, 'k' => $key]);
        $row = $stmt->fetch();
        if ($row === false || $row['value_encrypted'] === null) {
            return $default;
        }
        $raw = (string) $row['value_encrypted'];

        if (in_array($key, self::SECRET_KEYS, true)) {
            try {
                return $this->encryption->decrypt($raw);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $raw;
    }

    public function set(int $officeId, string $key, ?string $value): void
    {
        $stored = null;
        if ($value !== null && $value !== '') {
            $stored = in_array($key, self::SECRET_KEYS, true)
                ? $this->encryption->encrypt($value)
                : $value;
        }

        $now = date('Y-m-d H:i:s');
        // UPSERT (office_id+key egyedi index)
        $exists = $this->pdo->prepare('SELECT id FROM office_settings WHERE office_id = :o AND `key` = :k LIMIT 1');
        $exists->execute(['o' => $officeId, 'k' => $key]);
        $id = $exists->fetchColumn();

        if ($id !== false) {
            $upd = $this->pdo->prepare('UPDATE office_settings SET value_encrypted = :v, updated_at = :u WHERE id = :id');
            $upd->execute(['v' => $stored, 'u' => $now, 'id' => $id]);
        } else {
            $ins = $this->pdo->prepare('INSERT INTO office_settings (office_id, `key`, value_encrypted, created_at, updated_at) VALUES (:o, :k, :v, :c, :u)');
            $ins->execute(['o' => $officeId, 'k' => $key, 'v' => $stored, 'c' => $now, 'u' => $now]);
        }
    }

    /**
     * Több beállítás kiolvasása alapértelmezésekkel (a titok-mezőknél a meglét
     * jelzésére használható; a nyers értéket csak szerkesztéskor töltjük vissza).
     *
     * @param list<string> $keys
     * @return array<string,?string>
     */
    public function many(int $officeId, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($officeId, $k);
        }

        return $out;
    }

    public function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }
}
