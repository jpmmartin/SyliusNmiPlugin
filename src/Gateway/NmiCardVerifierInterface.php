<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\CardVerification;

/**
 * Puts a card on file without charging it.
 *
 * An interface of its own rather than one more method on the public client interface: a method
 * added there would break every store that implements or decorates it.
 *
 * @internal
 */
interface NmiCardVerifierInterface
{
    /**
     * A zero-amount verification that also stores the card. The answer is a transaction: its id is
     * the initial transaction every later charge of the card cites, and it names the vault record
     * the card was stored in.
     *
     * @throws NmiDeclinedException  when the issuer declined the card
     * @throws NmiGatewayException   when the gateway refused the request
     * @throws NmiTransportException when the gateway did not answer, or answered that it could not
     */
    public function verifyAndStore(NmiGatewayConfiguration $configuration, CardVerification $card): NmiResponse;
}
