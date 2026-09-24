<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Recurring;

use JpmMartin\SyliusNmiPlugin\Recurring\NmiNoRecurringChargesPolicy;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;

final class NmiNoRecurringChargesPolicyTest extends TestCase
{
    /** What a store has until it provides its own answer: nothing opens recurring charges. */
    public function testNoPaymentOpensRecurringCharges(): void
    {
        self::assertFalse((new NmiNoRecurringChargesPolicy())->opensRecurringCharges(new Payment()));
    }
}
