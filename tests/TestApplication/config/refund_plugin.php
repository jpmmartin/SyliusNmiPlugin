<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The optional refund plugin's own configuration, imported only when the package is installed.
 *
 * This file is PHP rather than YAML for exactly one reason: a YAML `imports:` entry naming
 * `@SyliusRefundPlugin` cannot ask whether that bundle exists, and fails to compile the container
 * in the configuration that does not have it. The two configurations this suite must pass in are
 * then one checkout apart, with no file to edit between them.
 */
return static function (ContainerConfigurator $container): void {
    if (!class_exists(Sylius\RefundPlugin\SyliusRefundPlugin::class)) {
        return;
    }

    $container->import('@SyliusRefundPlugin/config/config.yaml');
};
