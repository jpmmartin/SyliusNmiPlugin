<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

/**
 * Forget one card at the gateway.
 *
 * **It carries the identifiers themselves, never a reference to the row.** By the time this is
 * handled the row is gone — that is the whole point of it — so anything that had to be looked up
 * again would be a message that can never be delivered. The payment method code travels rather than
 * its id for the same reason a code is what a store writes down: it survives.
 *
 * Sent asynchronously, which is what buys both halves of the deletion requirement at once. A
 * gateway that is down cannot make deleting a customer fail, because deleting a customer does not
 * talk to the gateway; and a purge that fails is retried by the transport rather than lost.
 *
 * @internal
 */
final class PurgeStoredCard
{
    public function __construct(
        public readonly string $vaultId,
        public readonly string $paymentMethodCode,
    ) {
    }
}
