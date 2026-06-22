<?php

declare(strict_types=1);

namespace App\Kernel;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * Konténer-bootstrap a parancssori (CLI) parancsokhoz — Slim app és session nélkül.
 */
final class CliKernel
{
    public static function container(string $root): ContainerInterface
    {
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        /** @var array<string,mixed> $settings */
        $settings = (require $root . '/config/settings.php')($root);
        date_default_timezone_set($settings['app']['timezone']);
        $GLOBALS['__app_settings'] = $settings;

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $root . '/config/container.php');

        return $builder->build();
    }
}
