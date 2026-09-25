<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

/**
 * Void, at the gateway, the authorisation a cancelled payment left open.
 *
 * **Queued once the cancellation has been saved**, so that a gateway that is down never makes
 * cancelling an order fail, and a void that fails is tried again by the transport rather than lost.
 *
 * **It carries the payment's id and nothing else.** Unlike a purge, whose row is gone by the time
 * it is handled, the payment is still there — and what is still open is read from the record when
 * the message is handled, not when it was sent, so a message handled twice asks the gateway once.
 *
 * @internal
 */
final class VoidAuthorization
{
    public function __construct(
        public readonly int $paymentId,
    ) {
    }
}
