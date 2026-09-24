<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Tests\JpmMartin\SyliusNmiPlugin\Double\DecoratingRecurringChargesPolicy;

/**
 * Which payments the store says open recurring charges. The answer is given to the test
 * application's decorator of the policy, which is where a store would give its own.
 */
final class NmiRecurringChargesContext implements Context
{
    /**
     * @BeforeScenario
     *
     * @AfterScenario
     */
    public function forgetWhatTheStoreSaid(): void
    {
        DecoratingRecurringChargesPolicy::reset();
    }

    /**
     * @Given the store says every payment opens recurring charges
     */
    public function theStoreSaysEveryPaymentOpensRecurringCharges(): void
    {
        DecoratingRecurringChargesPolicy::$answer = true;
    }
}
