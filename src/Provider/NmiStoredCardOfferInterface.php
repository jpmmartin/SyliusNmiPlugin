<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Provider;

use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Which stored cards this payment may be paid with, and which one was chosen.
 *
 * Both questions live here so that they cannot answer differently. The page offers what
 * `offeredFor()` returns and the charge takes what `chosenFor()` gives back, so **a card that was
 * never offered can never be charged** — not because the handler remembers to check, but because
 * there is nowhere else for it to get a card from.
 */
interface NmiStoredCardOfferInterface
{
    /**
     * Every card that belongs on this payment page, expired ones included.
     *
     * Expired cards are in the list because the shopper has to see why the card they were looking
     * for cannot be used; they are excluded from `chosenFor()` rather than from here.
     *
     * @return list<NmiStoredCardInterface>
     */
    public function offeredFor(PaymentInterface $payment, NmiGatewayConfiguration $configuration): array;

    /**
     * The offered card with this identifier, or null when there is none that may be charged.
     *
     * Null covers every way that can be true and deliberately does not distinguish them: another
     * shopper's card, a card stored against a different NMI account, an expired one, or an
     * identifier that names nothing at all.
     */
    public function chosenFor(PaymentInterface $payment, NmiGatewayConfiguration $configuration, string $id): ?NmiStoredCardInterface;
}
