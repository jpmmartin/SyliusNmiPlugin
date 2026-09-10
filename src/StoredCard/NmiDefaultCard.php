<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\StoredCard;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Keeps the promise that exactly one card is the default while any card exists.
 *
 * It lives in one place because it has three triggers that would otherwise each carry their own
 * copy of the rule: a first card being saved, a customer choosing a different one, and the default
 * being deleted. Three copies of an invariant is three chances to disagree.
 *
 * **Scoped to the payment method as well as the customer**, as `design.md` requires: a card stored
 * against one NMI account cannot be charged against another, so "the default" only means anything
 * within one account.
 *
 * Nothing here flushes. Every caller is already inside a transaction that commits on return.
 *
 * @internal
 */
final readonly class NmiDefaultCard
{
    public function __construct(
        private NmiStoredCardRepositoryInterface $repository,
    ) {
    }

    /** Makes this card the default and stops any sibling from being it. */
    public function promote(NmiStoredCardInterface $card): void
    {
        $customer = $card->getCustomer();
        $paymentMethod = $card->getPaymentMethod();

        if (null === $customer || null === $paymentMethod) {
            return;
        }

        foreach ($this->siblings($customer, $paymentMethod) as $sibling) {
            if ($sibling->getId() !== $card->getId()) {
                $sibling->setDefault(false);
            }
        }

        $card->setDefault(true);
    }

    /**
     * Makes this card the default only if its owner has none yet — which is what makes the first
     * card saved the default without a caller having to know whether it is the first.
     */
    public function promoteIfNoneYet(NmiStoredCardInterface $card): void
    {
        $customer = $card->getCustomer();
        $paymentMethod = $card->getPaymentMethod();

        if (null === $customer || null === $paymentMethod) {
            return;
        }

        foreach ($this->siblings($customer, $paymentMethod) as $sibling) {
            if ($sibling->getId() !== $card->getId() && $sibling->isDefault()) {
                return;
            }
        }

        $card->setDefault(true);
    }

    /**
     * Elects a replacement when the default is gone, or leaves none if that was the last card.
     *
     * Called after a deletion, when the row it removed may or may not have been the default: this
     * asks the survivors rather than being told, so it is correct either way.
     *
     * @param NmiStoredCardInterface|null $removed the card that has just gone, still holding its id
     */
    public function electIfNoneRemains(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        ?NmiStoredCardInterface $removed = null,
    ): void {
        $survivors = [];
        foreach ($this->siblings($customer, $paymentMethod) as $card) {
            if (null !== $removed && $card->getId() === $removed->getId()) {
                continue;
            }

            if ($card->isDefault()) {
                return;
            }

            $survivors[] = $card;
        }

        // The listing hands them back newest first, so this promotes the most recently saved.
        if ([] !== $survivors) {
            $survivors[0]->setDefault(true);
        }
    }

    /** @return list<NmiStoredCardInterface> */
    private function siblings(CustomerInterface $customer, PaymentMethodInterface $paymentMethod): array
    {
        return $this->repository->findByCustomer($customer, $paymentMethod);
    }
}
