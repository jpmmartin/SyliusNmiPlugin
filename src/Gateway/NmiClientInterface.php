<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;

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
}
