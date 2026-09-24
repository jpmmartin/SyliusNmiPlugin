<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargesPolicyInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * What a store's policy looks like, registered in the test application the way a store would
 * register its own: decorating the interface.
 *
 * Dormant unless a test says what to answer, so that every other test still meets the plugin's own
 * policy, which opens nothing. The answer is static because a scenario's pages are served by a
 * kernel rebooted between requests: an answer given to one instance would not reach the next.
 */
final class DecoratingRecurringChargesPolicy implements NmiRecurringChargesPolicyInterface
{
    public static ?bool $answer = null;

    public function __construct(public readonly NmiRecurringChargesPolicyInterface $inner)
    {
    }

    public static function reset(): void
    {
        self::$answer = null;
    }

    public function opensRecurringCharges(PaymentInterface $payment): bool
    {
        return self::$answer ?? $this->inner->opensRecurringCharges($payment);
    }
}
