<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * The charge as the plugin sends it when nobody has decorated the factory.
 *
 * Deliberately no more than the handler sent before this class existed: the amount and currency
 * of the payment, the order's number, the shopper's address, the authentication result, and how
 * the money is named. The description and the billing details the `Charge` can carry are left
 * empty — a billing address handed to the gateway can trip a store's own address-verification
 * rules, so filling it is a store's decision, taken in a decorator.
 *
 * @internal
 */
final class ChargeFactory implements ChargeFactoryInterface
{
    public function forToken(PaymentInterface $payment, string $token, array $payload, bool $storeCard): Charge
    {
        return new Charge(
            paymentToken: $token,
            amount: (int) $payment->getAmount(),
            currencyCode: (string) $payment->getCurrencyCode(),
            // Sent on every charge so a merchant can find the transaction in the gateway's own
            // portal after a lost response. It is not a reconciliation mechanism: nothing in the
            // API looks a payment up by it.
            orderId: OrderReference::of($payment->getOrder()),
            ipAddress: $this->stringOrNull($payload['ip_address'] ?? null),
            threeDSecure: ThreeDSecureResult::fromPayload($payload),
            storeCard: $storeCard,
        );
    }

    /**
     * The same charge, paid for by a card the gateway already holds.
     *
     * Everything about the amount, the order and the authentication is identical — which is the
     * whole of *a stored card charges like a fresh one*. What differs is where the money comes
     * from, and that is one object further down.
     */
    public function forStoredCard(PaymentInterface $payment, NmiStoredCardInterface $card, array $payload): Charge
    {
        return new Charge(
            paymentToken: null,
            amount: (int) $payment->getAmount(),
            currencyCode: (string) $payment->getCurrencyCode(),
            orderId: OrderReference::of($payment->getOrder()),
            ipAddress: $this->stringOrNull($payload['ip_address'] ?? null),
            threeDSecure: ThreeDSecureResult::fromPayload($payload),
            storedCard: new StoredCard(
                vaultId: (string) $card->getVaultId(),
                billingId: $card->getBillingId(),
                // Cited when the card has one to cite. A card added from the account area was
                // never charged, so it has none, and the gateway takes the sale regardless.
                initialTransactionId: $card->getVaultingTransactionId(),
            ),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
