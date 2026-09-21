<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * The charge of a card on file, made by the store with nobody present.
 *
 * Its own factory rather than a method on the public charge factory, because a method added to that
 * interface would break every store that implements it. What it builds differs from a shopper's
 * stored-card charge in exactly what the absence of the shopper changes: the charge is declared
 * merchant-initiated and cites the verification that put the card on file, and it carries no 3-D
 * Secure result and no IP address, because there is no browser. The amount, the currency and the
 * order reference are the payment's, as always.
 *
 * @internal
 */
final class CardOnFileChargeFactory
{
    /**
     * @param array<string, mixed> $extra fields of the gateway's API the plugin does not model,
     *                                    merged beneath the plugin's own exactly as on any charge —
     *                                    so the declaration below is never changed by them
     */
    public function forCardOnFile(PaymentInterface $payment, NmiCardOnFileInterface $card, array $extra = []): Charge
    {
        return new Charge(
            paymentToken: null,
            amount: (int) $payment->getAmount(),
            currencyCode: (string) $payment->getCurrencyCode(),
            orderId: OrderReference::of($payment->getOrder()),
            storedCard: new StoredCard(
                vaultId: (string) $card->getVaultId(),
                billingId: $card->getBillingId(),
                initialTransactionId: $card->getInitialTransactionId(),
                initiatedBy: StoredCard::INITIATED_BY_MERCHANT,
            ),
            extra: $extra,
        );
    }
}
