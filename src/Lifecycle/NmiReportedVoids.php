<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Lifecycle;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The payments the gateway reported voided in the current request or message.
 *
 * Written by the handler that reflects a gateway event onto its payment, before it cancels the
 * payment, and read by the listener that queues the void of what a cancellation left open. A void
 * made in the gateway's portal leaves nothing open, although the store's record still shows the
 * authorisation: reflecting an event writes no transaction.
 *
 * Kept in memory for the same reason approved charges are: the cancellation and the event arrive in
 * one request, and nothing in the database says it in time. Forgotten between requests and between
 * a worker's messages.
 *
 * @internal
 */
final class NmiReportedVoids implements ResetInterface
{
    /** @var array<string, true> */
    private array $reported = [];

    public function report(PaymentInterface $payment): void
    {
        $this->reported[self::key($payment)] = true;
    }

    public function isReported(PaymentInterface $payment): bool
    {
        return isset($this->reported[self::key($payment)]);
    }

    public function reset(): void
    {
        $this->reported = [];
    }

    /** Its row, which outlives the object; the object only for a payment not saved yet. */
    private static function key(PaymentInterface $payment): string
    {
        $id = $payment->getId();

        return null !== $id ? 'id:' . $id : 'object:' . spl_object_id($payment);
    }
}
