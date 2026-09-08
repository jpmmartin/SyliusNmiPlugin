<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Recorder;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiVaultRecord;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Files the card the gateway kept, against the customer it belongs to and the account it lives in.
 *
 * Callers go through this rather than building the entity, because the two identifiers that make a
 * stored card usable — the vault reference and the transaction that created it — are read out of
 * the same response and are easy to file separately by mistake.
 */
interface NmiStoredCardRecorderInterface
{
    /**
     * The row is persisted but not flushed: the payment-request command bus wraps each handler in
     * a Doctrine transaction, so the flush belongs to the handler's own commit.
     *
     * **Null when the gateway kept nothing**, or described what it kept in a way this cannot read.
     * By the time this runs the shopper has already been charged and the payment has already
     * succeeded, so a bookkeeping problem must not become a failed order: the card is not filed
     * and the purchase stands.
     */
    public function record(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        NmiResponse $response,
    ): ?NmiStoredCardInterface;

    /**
     * The same filing, for a card stored from the account area rather than alongside a payment.
     *
     * That call answers with a customer rather than a transaction, so there is no transaction to
     * cite later — the column stays null — and the card is described by the gateway's own masked
     * number rather than by anything the browser reported.
     *
     * Null when the answer carried no card this can read.
     */
    public function recordVaulted(
        CustomerInterface $customer,
        PaymentMethodInterface $paymentMethod,
        NmiVaultRecord $record,
    ): ?NmiStoredCardInterface;
}
