<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CardOnFile;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The payments whose charge the gateway approved in the current request or message.
 *
 * Written by the handlers that complete a payment on an approved answer — the checkout's, a card on
 * file's and a recurring credential's — before they apply the transition, and read by the listener
 * that refuses to complete a held payment otherwise. The order screen charges and then completes in
 * one request, so its approval is here when its transition runs.
 *
 * Kept in memory rather than read from the database, because nothing there says it in time: the
 * transaction log records a declined sale as a sale, and the charge's own record is completed only
 * after the payment's transition. Forgotten between requests and between a worker's messages.
 *
 * @internal
 */
final class NmiApprovedCharges implements ResetInterface
{
    /** @var array<string, true> */
    private array $approved = [];

    public function approve(PaymentInterface $payment): void
    {
        $this->approved[self::key($payment)] = true;
    }

    public function isApproved(PaymentInterface $payment): bool
    {
        return isset($this->approved[self::key($payment)]);
    }

    public function reset(): void
    {
        $this->approved = [];
    }

    /** Its row, which outlives the object; the object only for a payment not saved yet. */
    private static function key(PaymentInterface $payment): string
    {
        $id = $payment->getId();

        return null !== $id ? 'id:' . $id : 'object:' . spl_object_id($payment);
    }
}
