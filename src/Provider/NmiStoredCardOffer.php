<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

final class NmiStoredCardOffer implements NmiStoredCardOfferInterface
{
    public function __construct(
        private readonly NmiCardSavingCustomerProviderInterface $customerProvider,
        private readonly NmiStoredCardRepositoryInterface $repository,
    ) {
    }

    public function offeredFor(PaymentInterface $payment, NmiGatewayConfiguration $configuration): array
    {
        $method = $payment instanceof CorePaymentInterface ? $payment->getMethod() : null;
        if (!$method instanceof PaymentMethodInterface) {
            return [];
        }

        // The same three conditions that decide who may *save* a card decide who may use one:
        // the operator turned card storage on, somebody is signed in, and the order is theirs.
        // Asking the one service that already answers that keeps the two from drifting apart —
        // and it means turning the setting off hides the cards a store collected while it was on,
        // which is the reading that leaves a switched-off store looking switched off.
        $customer = $this->customerProvider->forPayment($payment, $configuration);
        if (null === $customer) {
            return [];
        }

        // Narrowed to this payment method, which is where "cards do not cross gateway accounts"
        // is actually enforced: a vault reference is meaningless to any NMI account but the one
        // that issued it, so charging it elsewhere would fail at the gateway if it got that far.
        //
        // A card whose account the issuer has closed is dropped here rather than shown greyed out
        // like an expired one. An expired card can be renewed and the shopper knows which card it
        // is; a closed one is gone, and offering it back would be offering something that cannot
        // come back.
        return array_values(array_filter(
            $this->repository->findByCustomer($customer, $method),
            static fn (NmiStoredCardInterface $card): bool => $card->isUsable(),
        ));
    }

    public function chosenFor(PaymentInterface $payment, NmiGatewayConfiguration $configuration, string $id): ?NmiStoredCardInterface
    {
        foreach ($this->offeredFor($payment, $configuration) as $card) {
            if ((string) $card->getId() !== $id) {
                continue;
            }

            // Shown in the list so the shopper knows why it is missing from the choices, refused
            // here so that posting its identifier by hand achieves nothing the page does not.
            return $card->isExpired() ? null : $card;
        }

        return null;
    }
}
