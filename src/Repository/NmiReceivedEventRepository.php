<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

/** @internal */
final class NmiReceivedEventRepository extends EntityRepository implements NmiReceivedEventRepositoryInterface
{
    public function deleteReceivedBefore(\DateTimeImmutable $moment): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.receivedAt < :moment')
            ->setParameter('moment', $moment)
            ->getQuery()
            ->execute()
        ;
    }
}
