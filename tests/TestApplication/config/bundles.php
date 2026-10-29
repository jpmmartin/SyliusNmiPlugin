<?php

declare(strict_types=1);

$bundles = [
    JpmMartin\SyliusNmiPlugin\JpmMartinSyliusNmiPlugin::class => ['all' => true],
];

// The optional refund plugin, registered only when it is installed, so one test application
// serves both configurations this suite has to pass in: with it and without it. A consuming
// store registers these in its own bundles.php as usual — the conditional exists because CI
// installs and removes the package around the same checkout.
//
// Not one bundle: the refund plugin generates credit memos as PDFs and its services reference
// Snappy's directly, so registering it alone fails to compile the container. And from 2.2 it
// stores them through Gaufrette, which Sylius 2.3 no longer registers — Sylius 2.2's test
// application still does, and a bundle listed twice is registered once.
if (class_exists(Sylius\RefundPlugin\SyliusRefundPlugin::class)) {
    if (class_exists(Knp\Bundle\GaufretteBundle\KnpGaufretteBundle::class)) {
        $bundles[Knp\Bundle\GaufretteBundle\KnpGaufretteBundle::class] = ['all' => true];
    }
    $bundles[Knp\Bundle\SnappyBundle\KnpSnappyBundle::class] = ['all' => true];
    $bundles[Sylius\PdfGenerationBundle\SyliusPdfGenerationBundle::class] = ['all' => true];
    $bundles[Sylius\RefundPlugin\SyliusRefundPlugin::class] = ['all' => true];
}

return $bundles;
