<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/**
 * A card to keep, with no money moving: the browser's token and who the card belongs to.
 *
 * No amount and no currency, deliberately — this is the shape of a request that cannot charge.
 */
final class VaultCard
{
    public function __construct(
        public readonly string $paymentToken,
        public readonly ?BillingDetails $billing = null,
        /**
         * What the browser said the card's brand is, and the one thing this call cannot learn
         * from the gateway.
         *
         * Creating a vault record answers with the masked number and the expiry and **no brand** —
         * established against the gateway, where a charge that stores a card does return one. So
         * the brand travels from the browser or the card cannot be described at all, which would
         * leave a record at the gateway with nothing here pointing at it.
         */
        public readonly ?string $brand = null,
    ) {
    }
}
