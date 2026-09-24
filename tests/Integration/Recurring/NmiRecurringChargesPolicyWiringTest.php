<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Recurring;

use JpmMartin\SyliusNmiPlugin\Recurring\NmiNoRecurringChargesPolicy;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargesPolicyInterface;
use Sylius\Component\Core\Model\Payment;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\DecoratingRecurringChargesPolicy;

/**
 * The seam a store replaces is the interface's alias; until it does, the answer is always no.
 *
 * The test application decorates that alias the way a store would, so what the interface resolves
 * to here is the decorator — which proves the seam takes a decorator — and the plugin's own policy
 * is the one it wraps.
 */
final class NmiRecurringChargesPolicyWiringTest extends KernelTestCase
{
    public function testTheInterfaceResolvesToThePolicyThatOpensNothing(): void
    {
        self::bootKernel();

        $policy = self::getContainer()->get(NmiRecurringChargesPolicyInterface::class);

        self::assertInstanceOf(DecoratingRecurringChargesPolicy::class, $policy);
        self::assertInstanceOf(NmiNoRecurringChargesPolicy::class, $policy->inner);
        self::assertFalse($policy->opensRecurringCharges(new Payment()), 'Dormant, the decorator must leave the plugin\'s answer alone.');
    }
}
