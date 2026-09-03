<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
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
    public function findOneByTransactionIdAndType(string $transactionId, string $type): ?NmiTransactionInterface;

    /**
     * What has been refunded against one transaction, in minor units, as a negative number
     * or zero. The gateway keeps this balance and refuses a refund beyond it, but will not
     * report it, so the store has to keep its own count.
     */
    public function sumRefundedAgainst(string $transactionId): int;
}
