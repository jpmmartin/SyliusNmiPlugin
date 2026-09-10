<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Refund;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * The transaction that actually took a payment's money — the one a refund or a void reverses.
 *
 * A capture when there was one, otherwise the sale, otherwise the authorisation: the latest of
 * each, because a retried operation leaves the newest row as the one the gateway honoured. Shared
 * by the order screen's refund and by everything the refund plugin asks, so that both paths point
 * at the same transaction and agree on what has been given back against it.
 *
 * @internal
 */
final class MoneyTakingTransactionProvider
{
    private const TYPES = [
        NmiTransactionInterface::TYPE_CAPTURE,
        NmiTransactionInterface::TYPE_SALE,
        NmiTransactionInterface::TYPE_AUTH,
    ];

    public function __construct(private readonly NmiTransactionRepositoryInterface $transactionRepository)
    {
    }

    public function forPayment(PaymentInterface $payment): ?NmiTransactionInterface
    {
        foreach (self::TYPES as $type) {
            $transaction = $this->transactionRepository->findLatestForPayment($payment, $type);
            if (null !== $transaction && null !== $transaction->getTransactionId()) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * What has already gone back against a transaction, as a positive amount in minor units.
     *
     * Refunds are recorded with the sign the gateway reports them with, which is negative, so
     * the sum is taken at its absolute value rather than trusted to a sign convention.
     */
    public function returnedAgainst(NmiTransactionInterface $transaction): int
    {
        $transactionId = $transaction->getTransactionId();
        if (null === $transactionId) {
            return 0;
        }

        return abs($this->transactionRepository->sumRefundedAgainst($transactionId));
    }

    /** What is still there to give back, never below zero. */
    public function remainingOn(NmiTransactionInterface $transaction): int
    {
        return max(0, (int) $transaction->getAmount() - $this->returnedAgainst($transaction));
    }
}
