<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * The charge of a recurring credential, made by the store with nobody present.
 *
 * Built exactly as a card on file's charge is — merchant-initiated, citing the first transaction, no
 * 3-D Secure result and no IP address — so the declaration is written once, where the client turns a
 * stored card into the gateway's fields. The amount, the currency and the order reference are the
 * payment's: a renewal is charged for its own order, at its own amount.
 *
 * Nothing marks the use as one of a recurring series: the field the gateway's guides name for that is
 * refused by its payments API, whatever its value or wherever it is placed, so none is sent.
 *
 * @internal
 */
final class RecurringChargeFactory
{
    /**
     * @param array<string, mixed> $extra fields of the gateway's API the plugin does not model,
     *                                    merged beneath the plugin's own exactly as on any charge —
     *                                    so the declaration below is never changed by them
     */
    public function forRecurringCredential(PaymentInterface $payment, NmiRecurringCredentialInterface $credential, array $extra = []): Charge
    {
        return new Charge(
            paymentToken: null,
            amount: (int) $payment->getAmount(),
            currencyCode: (string) $payment->getCurrencyCode(),
            orderId: OrderReference::of($payment->getOrder()),
            storedCard: new StoredCard(
                vaultId: (string) $credential->getVaultId(),
                billingId: $credential->getBillingId(),
                initialTransactionId: $credential->getInitialTransactionId(),
                initiatedBy: StoredCard::INITIATED_BY_MERCHANT,
            ),
            extra: $extra,
        );
    }
}
