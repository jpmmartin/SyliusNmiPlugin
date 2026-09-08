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
    ) {
    }
}
