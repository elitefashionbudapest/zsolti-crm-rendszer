<?php

declare(strict_types=1);

namespace App\Support;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use RuntimeException;

/**
 * Érzékeny mezők és titkok titkosítása nyugalmi állapotban (Defuse).
 * A kulcs az APP_KEY (ASCII-safe Defuse kulcs), kizárólag az .env-ből.
 */
final class Encryption
{
    private Key $key;

    public function __construct(string $asciiKey)
    {
        if ($asciiKey === '') {
            throw new RuntimeException(
                'Hiányzik az APP_KEY. Generáld: php bin/console app:generate-key'
            );
        }

        $this->key = Key::loadFromAsciiSafeString($asciiKey);
    }

    public static function generateKey(): string
    {
        return Key::createNewRandomKey()->saveToAsciiSafeString();
    }

    public function encrypt(string $plaintext): string
    {
        return Crypto::encrypt($plaintext, $this->key);
    }

    public function decrypt(string $ciphertext): string
    {
        return Crypto::decrypt($ciphertext, $this->key);
    }

    /** Null-biztos segédek a modellekhez. */
    public function encryptNullable(?string $plaintext): ?string
    {
        return $plaintext === null ? null : $this->encrypt($plaintext);
    }

    public function decryptNullable(?string $ciphertext): ?string
    {
        return $ciphertext === null ? null : $this->decrypt($ciphertext);
    }
}
