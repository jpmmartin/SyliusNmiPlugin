<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * The optional refund plugin's routes, imported only when the package is installed — PHP for the
 * same reason as `refund_plugin.php`, since a YAML import cannot ask whether a bundle exists.
 *
 * Not optional for a store that installs it: that plugin puts a credit-memo link in the admin
 * sidebar, which every admin page renders, so a store that registers the bundle without importing
 * its routes gets HTTP 500 on the whole admin rather than a missing menu entry.
 */
return static function (RoutingConfigurator $routes): void {
    if (!class_exists(Sylius\RefundPlugin\SyliusRefundPlugin::class)) {
        return;
    }

    $routes->import('@SyliusRefundPlugin/config/routes.yaml');
};
