<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use Doctrine\DBAL\LockMode;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/** @internal */
class NmiCardOnFileRepository extends EntityRepository implements NmiCardOnFileRepositoryInterface
{
    public function findHeldBy(PaymentInterface $payment): ?NmiCardOnFileInterface
    {
        /** @var NmiCardOnFileInterface|null $card */
        $card = $this->createQueryBuilder('o')
            ->andWhere('o.payment = :payment')
            ->andWhere('o.releasedAt IS NULL')
            ->setParameter('payment', $payment)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $card;
    }

    public function findHeldByForUpdate(PaymentInterface $payment): ?NmiCardOnFileInterface
    {
        /** @var NmiCardOnFileInterface|null $card */
        $card = $this->createQueryBuilder('o')
            ->andWhere('o.payment = :payment')
            ->andWhere('o.releasedAt IS NULL')
            ->setParameter('payment', $payment)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;

        return $card;
    }

    public function findHeldUnder(PaymentMethodInterface $paymentMethod): array
    {
        /** @var list<NmiCardOnFileInterface> $cards */
        $cards = $this->createQueryBuilder('o')
            ->andWhere('o.paymentMethod = :paymentMethod')
            ->andWhere('o.releasedAt IS NULL')
            ->setParameter('paymentMethod', $paymentMethod)
            ->getQuery()
            ->getResult()
        ;

        return $cards;
    }
}
