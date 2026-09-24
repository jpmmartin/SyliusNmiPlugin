<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recurring;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Keeps the recurring credential a checkout's approved transaction opened — the sale, the
 * authorisation or the verification, whichever the checkout made.
 *
 * Nothing is kept without the stored card's reference or without a customer to own it: a
 * credential that could not be charged, or could not be let go when its customer is deleted, would
 * be a promise the store only thinks it holds.
 *
 * @internal
 */
final class NmiRecurringCredentialKeeper
{
    /** @param FactoryInterface<NmiRecurringCredentialInterface> $factory */
    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly ObjectManager $manager,
    ) {
    }

    public function keep(PaymentInterface $payment, PaymentMethodInterface $method, NmiResponse $response): ?NmiRecurringCredentialInterface
    {
        $vaultId = $response->customerVaultId;
        $customer = $payment->getOrder()?->getCustomer();
        if (null === $vaultId || '' === $vaultId || !$customer instanceof CustomerInterface) {
            return null;
        }

        $credential = $this->factory->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId($vaultId);
        // The transaction that first stored the card: every later charge of it cites this one.
        $credential->setInitialTransactionId($response->transactionId);
        $credential->setBrand($response->card?->brand);
        $credential->setLastFour($response->card?->lastFour);
        $credential->setExpiryMonth($response->card?->expiryMonth);
        $credential->setExpiryYear($response->card?->expiryYear);
        $this->manager->persist($credential);

        return $credential;
    }
}
