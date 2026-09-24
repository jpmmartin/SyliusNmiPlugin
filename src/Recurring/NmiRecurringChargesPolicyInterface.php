<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Whether a payment opens recurring charges — the store's decision, never the plugin's.
 *
 * A payment that opens recurring charges keeps its card at checkout on that promise: the shopper is
 * shown the store's statement of it before paying, and the card is kept as a recurring credential
 * the store may charge again, for new payments, without the shopper. What the store renews, how
 * often and for how much are the store's own and never reach this plugin.
 *
 * The implementation the plugin ships answers no for every payment, so a store that never replaces
 * it sees no change. It is asked when the pay page is prepared and again when the card comes back,
 * so the answer that decides what is kept is the one given at that moment — never something the
 * shopper or a client of the shop API sent.
 *
 * Replace or decorate the service aliased to this interface.
 */
interface NmiRecurringChargesPolicyInterface
{
    public function opensRecurringCharges(PaymentInterface $payment): bool;
}
