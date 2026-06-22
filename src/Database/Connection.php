<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * PDO-kapcsolat előállítása a beállításokból (sqlite vagy mysql).
 * Minden lekérdezés előkészített (prepared) — SQL-injection ellen védve.
 */
final class Connection
{
    /** @param array<string,mixed> $config */
    public static function create(array $config, string $rootPath): PDO
    {
        $driver = (string) ($config['driver'] ?? 'mysql');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? 'storage/database.sqlite');
            if ($database !== ':memory:' && !str_starts_with($database, '/') && !preg_match('/^[A-Za-z]:/', $database)) {
                $database = $rootPath . '/' . ltrim($database, '/');
            }
            if ($database !== ':memory:') {
                $dir = dirname($database);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
            }
            $pdo = new PDO('sqlite:' . $database, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');

            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['name'] ?? 'aegis_crm'),
            (string) ($config['charset'] ?? 'utf8mb4'),
        );

        return new PDO(
            $dsn,
            (string) ($config['user'] ?? 'root'),
            (string) ($config['pass'] ?? ''),
            $options,
        );
    }
}
