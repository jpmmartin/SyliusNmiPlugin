<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use Doctrine\ORM\Query;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

class NmiTransactionRepository extends EntityRepository implements NmiTransactionRepositoryInterface
{
    public function findByTransactionId(string $transactionId): array
    {
        /** @var list<NmiTransactionInterface> $transactions */
        $transactions = $this->findBy(['transactionId' => $transactionId], ['id' => 'ASC']);

        return $transactions;
    }

    public function findOneByTransactionIdAndType(string $transactionId, string $type): ?NmiTransactionInterface
    {
        /** @var NmiTransactionInterface|null $transaction */
        $transaction = $this->findOneBy(['transactionId' => $transactionId, 'type' => $type]);

        return $transaction;
    }

    public function sumRefundedAgainst(string $transactionId): int
    {
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COALESCE(SUM(t.amount), 0)')
            ->from($this->getEntityName(), 't')
            ->andWhere('t.parentTransactionId = :transactionId')
            ->andWhere('t.type = :type')
            ->setParameter('transactionId', $transactionId)
            ->setParameter('type', NmiTransactionInterface::TYPE_REFUND)
            ->getQuery()
        ;

        // Sylius installs an SQL walker as a default query hint that appends "ORDER BY id" to
        // every DQL query. It does not exempt aggregates, and ordering by an ungrouped column
        // is an error on PostgreSQL, so this one query opts out.
        $sum = $query
            ->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [])
            ->getSingleScalarResult()
        ;

        return (int) $sum;
    }
}
