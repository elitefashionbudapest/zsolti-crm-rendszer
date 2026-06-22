<?php

declare(strict_types=1);

/**
 * Alkalmazás-beállítások a környezeti változókból.
 * A titkokat soha nem írjuk a kódba — csak az .env-ből olvassuk.
 */
return static function (string $rootPath): array {
    $env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $default;
    $bool = static fn (string $key, bool $default = false): bool
        => filter_var($env($key, $default), FILTER_VALIDATE_BOOL);

    return [
        'root' => $rootPath,
        'app' => [
            'name' => (string) $env('APP_NAME', 'AegisCRM'),
            'env' => (string) $env('APP_ENV', 'production'),
            'debug' => $bool('APP_DEBUG', false),
            'url' => (string) $env('APP_URL', 'http://localhost:8080'),
            'timezone' => (string) $env('APP_TIMEZONE', 'Europe/Budapest'),
            'key' => (string) $env('APP_KEY', ''),
        ],
        'db' => [
            'driver' => (string) $env('DB_DRIVER', 'mysql'),
            'host' => (string) $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', 3306),
            'name' => (string) $env('DB_NAME', 'aegis_crm'),
            'user' => (string) $env('DB_USER', 'root'),
            'pass' => (string) $env('DB_PASS', ''),
            'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
            'database' => (string) $env('DB_DATABASE', 'storage/database.sqlite'),
        ],
        'session' => [
            'name' => (string) $env('SESSION_NAME', 'aegis_session'),
            'lifetime' => (int) $env('SESSION_LIFETIME', 7200),
            'secure' => $bool('SESSION_SECURE', true),
        ],
        'log' => [
            'level' => (string) $env('LOG_LEVEL', 'warning'),
            'path' => $rootPath . '/storage/logs/app.log',
        ],
        'paths' => [
            'templates' => $rootPath . '/templates',
            'cache' => $rootPath . '/storage/cache',
            'uploads' => $rootPath . '/storage/uploads',
        ],
        'ai' => [
            'key' => (string) $env('ANTHROPIC_API_KEY', ''),
            'model' => (string) $env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        ],
    ];
};
