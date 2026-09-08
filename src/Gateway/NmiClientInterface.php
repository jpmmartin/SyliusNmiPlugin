<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiExceptionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\VaultCard;

/**
 * The one code path to the gateway. Every method returns an approved response or throws one
 * of exactly three exceptions.
 *
 * @throws NmiDeclinedException the issuer declined
 * @throws NmiGatewayException the gateway rejected or errored
 * @throws NmiTransportException the gateway could not be reached
 */
interface NmiClientInterface
{
    public function sale(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse;

    public function authorize(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse;

    /** Flags an authorisation for settlement; the amount may be at most the authorised one. */
    public function capture(NmiGatewayConfiguration $configuration, string $transactionId, int $amount, string $currencyCode): NmiResponse;

    /** Cancels an unsettled sale, capture or authorisation. */
    public function void(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse;

    /**
     * Reverses a transaction; a null amount refunds it in full. The result is a transaction of
     * its own, with its own identifier and a negative amount, and it never appears on the
     * transaction it reverses — so a caller that wants to know what has been refunded has to
     * keep that record itself.
     */
    public function refund(NmiGatewayConfiguration $configuration, string $transactionId, ?int $amount, string $currencyCode): NmiResponse;

    /**
     * Reads a transaction back. The state it reports is a free string the gateway enumerates
     * nowhere, and it carries no settlement marker and no refunded total.
     */
    public function retrieve(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse;

    /**
     * Keeps a card without charging for it, for a shopper adding one from their account.
     *
     * Its own resource, not the payments endpoint: that one has no way to store a card without a
     * transaction. What comes back is a customer object, so it reports no amount and no
     * authorisation — nothing that could reach a statement.
     *
     * @throws NmiExceptionInterface when the gateway refuses the card or cannot be reached
     */
    public function createVaultRecord(NmiGatewayConfiguration $configuration, VaultCard $card): NmiVaultRecord;

    /**
     * Forgets a stored card at the gateway.
     *
     * Deleting one that is already gone answers 404, which this reports as a gateway refusal — the
     * caller decides that a card the gateway no longer has is a purge that succeeded, because a
     * retry must not loop on it.
     *
     * @throws NmiExceptionInterface when the gateway refuses or cannot be reached
     */
    public function deleteVaultRecord(NmiGatewayConfiguration $configuration, string $vaultId): void;
}
