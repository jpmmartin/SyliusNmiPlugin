<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

return function (ContainerConfigurator $container) {
    if (str_starts_with($container->env(), 'test')) {
        $container->import('../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services.xml');
        $container->import('@JpmMartinSyliusNmiPlugin/tests/Behat/Resources/services.xml');

        // **Nothing in the test suite may reach the real gateway.** Behat found this the hard
        // way: a scenario that deleted a saved card asked NMI's production host to forget a vault
        // record, using a made-up key, and reported the refusal as a store bug. Swapping the
        // client per scenario did not hold — the kernel is rebooted between requests and the
        // replacement went with it — so the fake is the default here instead, and a test that
        // wants particular behaviour prepares it on the instance it fetches.
        $container->services()
            ->set('jpm_martin_sylius_nmi.gateway.client', FakeNmiClient::class)
        ;

        // Services fetched from the container by hand — by an integration test, or by the
        // scripts that verify a task against the gateway's sandbox. They are private in the
        // plugin, and a private service with no consumer yet is removed when the container
        // compiles, which is correct behaviour rather than something to work around in the
        // plugin itself.
        $container->services()
            ->alias('test.jpm_martin_sylius_nmi.recorder.transaction', 'jpm_martin_sylius_nmi.recorder.transaction')
            ->public()
            ->alias('test.jpm_martin_sylius_nmi.gateway.client', 'jpm_martin_sylius_nmi.gateway.client')
            ->public()
            ->alias('test.sylius.announcer.payment_request', 'sylius.announcer.payment_request')
            ->public()
        ;
    }
};
