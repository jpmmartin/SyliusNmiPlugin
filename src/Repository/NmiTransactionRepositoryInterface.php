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
}
