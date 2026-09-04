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
// Three bundles, not one: the refund plugin generates credit memos as PDFs and its services
// reference Snappy's directly, so registering it alone fails to compile the container. This is
// the same list its own test application registers.
if (class_exists(Sylius\RefundPlugin\SyliusRefundPlugin::class)) {
    $bundles[Knp\Bundle\SnappyBundle\KnpSnappyBundle::class] = ['all' => true];
    $bundles[Sylius\PdfGenerationBundle\SyliusPdfGenerationBundle::class] = ['all' => true];
    $bundles[Sylius\RefundPlugin\SyliusRefundPlugin::class] = ['all' => true];
}

return $bundles;
