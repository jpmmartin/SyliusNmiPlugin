<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The payments whose money has already gone back at the gateway in the current request or message.
 *
 * Written before a payment is marked refunded by everything that moved the money first, or learned
 * that it had moved: the order screen's *Refund*, the refund plugin's last refund of a payment, and a
 * refund the gateway reports from its portal. Read by the listener that refunds a payment at the
 * gateway when anything else marks it refunded, so that none of those asks for the money twice.
 *
 * Kept in memory for the same reason approved charges and reported voids are: the money moves and
 * the transition runs in one request, and nothing in the database says it in time. Forgotten between
 * requests and between a worker's messages.
 *
 * @internal
 */
final class NmiRefundedPayments implements ResetInterface
{
    /** @var array<string, true> */
    private array $refunded = [];

    public function markRefunded(PaymentInterface $payment): void
    {
        $this->refunded[self::key($payment)] = true;
    }

    public function isRefunded(PaymentInterface $payment): bool
    {
        return isset($this->refunded[self::key($payment)]);
    }

    public function reset(): void
    {
        $this->refunded = [];
    }

    /** Its row, which outlives the object; the object only for a payment not saved yet. */
    private static function key(PaymentInterface $payment): string
    {
        $id = $payment->getId();

        return null !== $id ? 'id:' . $id : 'object:' . spl_object_id($payment);
    }
}
