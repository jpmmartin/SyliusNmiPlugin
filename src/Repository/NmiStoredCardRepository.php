<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

class NmiStoredCardRepository extends EntityRepository implements NmiStoredCardRepositoryInterface
{
    public function findByCustomer(CustomerInterface $customer, ?PaymentMethodInterface $paymentMethod = null): array
    {
        $criteria = ['customer' => $customer];
        if (null !== $paymentMethod) {
            $criteria['paymentMethod'] = $paymentMethod;
        }

        /** @var list<NmiStoredCardInterface> $cards */
        $cards = $this->findBy($criteria, ['default' => 'DESC', 'id' => 'DESC']);

        return $cards;
    }

    public function findOneByCustomer(int $id, CustomerInterface $customer): ?NmiStoredCardInterface
    {
        /** @var NmiStoredCardInterface|null $card */
        $card = $this->findOneBy(['id' => $id, 'customer' => $customer]);

        return $card;
    }

    public function findOneDuplicate(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        string $brand,
        string $lastFour,
        int $expiryMonth,
        int $expiryYear,
    ): ?NmiStoredCardInterface {
        /** @var NmiStoredCardInterface|null $card */
        $card = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->andWhere('o.paymentMethod = :paymentMethod')
            // Case-insensitively, and deliberately. The browser names the brand `visa` and the
            // gateway names it `Visa`, so a plain comparison would match on MySQL's default
            // collation and miss on PostgreSQL — the same card, duplicated on one engine only.
            ->andWhere('LOWER(o.brand) = LOWER(:brand)')
            ->andWhere('o.lastFour = :lastFour')
            ->andWhere('o.expiryMonth = :expiryMonth')
            ->andWhere('o.expiryYear = :expiryYear')
            ->setParameter('customer', $customer)
            ->setParameter('paymentMethod', $paymentMethod)
            ->setParameter('brand', $brand)
            ->setParameter('lastFour', $lastFour)
            ->setParameter('expiryMonth', $expiryMonth)
            ->setParameter('expiryYear', $expiryYear)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $card;
    }
}
