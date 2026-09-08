<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Repository;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * Every read here starts from the customer.
 *
 * That is not a convenience: it is how one customer is kept from reaching another's card. A method
 * that took only an id would put the ownership check in the caller, where it can be forgotten.
 *
 * @extends RepositoryInterface<NmiStoredCardInterface>
 */
interface NmiStoredCardRepositoryInterface extends RepositoryInterface
{
    /**
     * The customer's cards, default first and newest next, optionally narrowed to one payment
     * method — a card stored against one NMI account cannot be charged against another.
     *
     * @return list<NmiStoredCardInterface>
     */
    public function findByCustomer(CustomerInterface $customer, ?PaymentMethodInterface $paymentMethod = null): array;

    /** One card, and only if it belongs to this customer. */
    public function findOneByCustomer(int $id, CustomerInterface $customer): ?NmiStoredCardInterface;

    /**
     * The card this customer already has, if any.
     *
     * A heuristic, and documented as one in `design.md`: the store never sees a card number, so
     * "the same card" can only mean the same brand, last four digits and expiry on the same
     * gateway account. Two genuinely different cards can collide, and the consequence of a
     * collision is that the shopper is told they already have it.
     */
    public function findOneDuplicate(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        string $brand,
        string $lastFour,
        int $expiryMonth,
        int $expiryYear,
    ): ?NmiStoredCardInterface;
}
