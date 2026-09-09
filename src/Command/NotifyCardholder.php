<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Command;

/**
 * Tell a shopper what their bank did to a card they saved.
 *
 * **A message rather than a send, and that is a correctness decision.** It is raised while handling
 * a webhook, and a mail server that is slow or down must not turn a delivery into a failure the
 * gateway then retries twenty times over three days. Queued, it is retried on its own terms and
 * parked in the failure transport if it never succeeds.
 *
 * It carries the card's identifier rather than the card, because by the time it is handled the
 * shopper may have deleted it — which is not an error, only a reason to send nothing.
 */
final class NotifyCardholder
{
    public function __construct(
        public readonly int $storedCardId,
        public readonly string $status,
        /** Captured when the event arrived: a worker has no request, so it has no locale either. */
        public readonly string $localeCode,
    ) {
    }
}
