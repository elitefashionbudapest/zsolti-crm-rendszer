<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

@session_start(); // CLI-ben kézzel, hogy a CSRF Guard aktív sessiont lásson

try {
    $app = App\Kernel\AppFactory::create(dirname(__DIR__));
    echo "BOOT OK\n";
} catch (Throwable $e) {
    echo 'BOOT HIBA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}
