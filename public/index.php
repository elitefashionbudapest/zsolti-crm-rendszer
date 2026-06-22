<?php

declare(strict_types=1);

use App\Kernel\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create(dirname(__DIR__));
$app->run();
