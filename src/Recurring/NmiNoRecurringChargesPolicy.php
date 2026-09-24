<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * The policy a store has until it provides its own: no payment opens recurring charges.
 *
 * @internal
 */
final class NmiNoRecurringChargesPolicy implements NmiRecurringChargesPolicyInterface
{
    public function opensRecurringCharges(PaymentInterface $payment): bool
    {
        return false;
    }
}
