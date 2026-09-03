<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $container) {
    if (str_starts_with($container->env(), 'test')) {
        $container->import('../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services.xml');
        $container->import('@JpmMartinSyliusNmiPlugin/tests/Behat/Resources/services.xml');

        // Services a test fetches from the container by hand. They are private in the plugin,
        // and a private service with no consumer yet is removed when the container compiles —
        // which is the correct behaviour, not something to work around in the plugin itself.
        $container->services()
            ->alias('test.jpm_martin_sylius_nmi.recorder.transaction', 'jpm_martin_sylius_nmi.recorder.transaction')
            ->public()
            ->alias('test.jpm_martin_sylius_nmi.gateway.client', 'jpm_martin_sylius_nmi.gateway.client')
            ->public()
        ;
    }
};
