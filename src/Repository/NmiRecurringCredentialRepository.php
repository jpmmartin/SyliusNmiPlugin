<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use Doctrine\DBAL\LockMode;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/** @internal */
class NmiRecurringCredentialRepository extends EntityRepository implements NmiRecurringCredentialRepositoryInterface
{
    public function findOpenedBy(PaymentInterface $payment): ?NmiRecurringCredentialInterface
    {
        /** @var NmiRecurringCredentialInterface|null $credential */
        $credential = $this->createQueryBuilder('o')
            ->andWhere('o.initialPayment = :payment')
            ->setParameter('payment', $payment)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $credential;
    }

    public function findForUpdate(int $id): ?NmiRecurringCredentialInterface
    {
        /** @var NmiRecurringCredentialInterface|null $credential */
        $credential = $this->createQueryBuilder('o')
            ->andWhere('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;

        return $credential;
    }

    public function findUnreleasedOf(CustomerInterface $customer): array
    {
        /** @var list<NmiRecurringCredentialInterface> $credentials */
        $credentials = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->andWhere('o.releasedAt IS NULL')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getResult()
        ;

        return $credentials;
    }

    public function findUnreleasedUnder(PaymentMethodInterface $paymentMethod): array
    {
        /** @var list<NmiRecurringCredentialInterface> $credentials */
        $credentials = $this->createQueryBuilder('o')
            ->andWhere('o.paymentMethod = :paymentMethod')
            ->andWhere('o.releasedAt IS NULL')
            ->setParameter('paymentMethod', $paymentMethod)
            ->getQuery()
            ->getResult()
        ;

        return $credentials;
    }
}
