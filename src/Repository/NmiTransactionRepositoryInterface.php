<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @extends RepositoryInterface<NmiTransactionInterface>
 */
interface NmiTransactionRepositoryInterface extends RepositoryInterface
{
    /**
     * Every row recorded under a gateway transaction id. A capture or a void keeps the id of
     * the authorisation it acts on, so one id may have several rows; they all belong to the
     * same payment.
     *
     * @return list<NmiTransactionInterface>
     */
    public function findByTransactionId(string $transactionId): array;

    /**
     * The one row for an operation on a transaction, or null. The pair is unique in the
     * schema, so this is what makes recording the same gateway answer twice a no-op instead
     * of a constraint violation.
     */
    /**
     * The newest row this store holds under a gateway transaction id, looking at both the id
     * itself and the id a refund was recorded against.
     *
     * **Both columns, because a webhook does not say which it is naming.** NMI models a refund as
     * an action on the original transaction, but the store records a refund under whatever id the
     * gateway answered with and keeps the original as the parent — so an inbound event may name
     * either, and searching one column would resolve half the events and silently treat the rest
     * as belonging to another store.
     */
    /**
     * Marks every transaction the store knows from a settled batch, and answers how many that was.
     *
     * **A batch, because that is the only shape settlement arrives in.** The gateway reports it
     * per batch and names every transaction the batch contained, most of which belong to other
     * stores on a shared account — so the identifiers that match nothing are simply not matched,
     * which is a normal outcome and not a failure to report.
     *
     * A bulk update rather than a load-and-set: a batch names as many transactions as the day had,
     * and hydrating them to write one column each would be the slowest possible way to say so.
     *
     * @param list<string> $transactionIds
     *
     * @return int how many rows were marked
     */
    public function markSettled(array $transactionIds, \DateTimeImmutable $settledAt): int;

    public function findOneByAnyTransactionId(string $transactionId): ?NmiTransactionInterface;

    public function findOneByTransactionIdAndType(string $transactionId, string $type): ?NmiTransactionInterface;

    /**
     * What has been refunded against one transaction, in minor units, as a negative number
     * or zero. The gateway keeps this balance and refuses a refund beyond it, but will not
     * report it, so the store has to keep its own count.
     */
    public function sumRefundedAgainst(string $transactionId): int;

    /**
     * The most recent transaction of a kind recorded against a payment — the authorisation a
     * capture has to claim, or the charge a refund has to reverse.
     */
    public function findLatestForPayment(PaymentInterface $payment, string $type): ?NmiTransactionInterface;
}
