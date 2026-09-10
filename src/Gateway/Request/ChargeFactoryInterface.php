<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Builds the charge the gateway is asked to perform, for a card the shopper just typed or for
 * one the gateway already holds.
 *
 * This is the seam for a store that wants the gateway told more than the plugin knows to say:
 * decorate the service this interface is aliased to, call the inner factory, and return the
 * charge with a description, billing details, or any field of the gateway's API added through
 * `Charge::with()`. The plugin sends what it is given and validates none of it; the gateway does,
 * and its refusal is surfaced like any other.
 */
interface ChargeFactoryInterface
{
    /**
     * @param array<string, mixed> $payload what the browser or the headless client posted, as the payment request carries it
     * @param bool $storeCard whether the gateway should keep the card after charging it
     */
    public function forToken(PaymentInterface $payment, string $token, array $payload, bool $storeCard): Charge;

    /** @param array<string, mixed> $payload */
    public function forStoredCard(PaymentInterface $payment, NmiStoredCardInterface $card, array $payload): Charge;
}
