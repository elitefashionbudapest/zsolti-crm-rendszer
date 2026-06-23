<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

// A .env a repó gyökerében VAGY a szülőmappában (szerveren, a git-munkafán kívül).
$envDir = is_file(__DIR__ . '/.env')
    ? __DIR__
    : (is_file(dirname(__DIR__) . '/.env') ? dirname(__DIR__) : null);
if ($envDir !== null) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$driver = $_ENV['DB_DRIVER'] ?? 'mysql';

if ($driver === 'sqlite') {
    $database = (string) ($_ENV['DB_DATABASE'] ?? 'storage/database.sqlite');
    $default = [
        'adapter' => 'sqlite',
        'name' => preg_replace('/\.sqlite$/', '', $database),
        'suffix' => '.sqlite',
    ];
} else {
    $default = [
        'adapter' => 'mysql',
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'name' => $_ENV['DB_NAME'] ?? 'aegis_crm',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ];
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment' => 'default',
        'default' => $default,
    ],
    'version_order' => 'creation',
];
