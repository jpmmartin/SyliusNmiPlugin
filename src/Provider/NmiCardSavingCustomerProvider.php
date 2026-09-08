<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Three conditions, and all three have to hold.
 *
 * **The operator turned it on.** Off is the feature absent, which is what a store that never
 * enables it is promised.
 *
 * **Somebody is signed in.** The platform's customer context is what answers that: it resolves a
 * customer only from an authenticated shop user, so a guest gets null from it no matter what the
 * order says. Asking the *order* instead would be wrong twice over — a guest order carries a
 * customer too, and an order placed with the email address of an existing account carries a
 * customer who owns a login the shopper never signed into.
 *
 * **The order is theirs.** Belt and braces against a payment reached with somebody else's session:
 * a card must never be filed against a customer who is not the one paying.
 */
final class NmiCardSavingCustomerProvider implements NmiCardSavingCustomerProviderInterface
{
    public function __construct(private readonly CustomerContextInterface $customerContext)
    {
    }

    public function forPayment(PaymentInterface $payment, NmiGatewayConfiguration $configuration): ?CustomerInterface
    {
        if (!$configuration->storeCards) {
            return null;
        }

        $customer = $this->customerContext->getCustomer();
        if (!$customer instanceof CustomerInterface || null === $customer->getId()) {
            return null;
        }

        if (!$payment instanceof CorePaymentInterface) {
            return null;
        }

        $orderCustomer = $payment->getOrder()?->getCustomer();
        if (null === $orderCustomer || $orderCustomer->getId() !== $customer->getId()) {
            return null;
        }

        return $customer;
    }
}
