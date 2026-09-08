<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/** A sale or an authorisation: the token from the browser plus the amount and the context. */
final class Charge
{
    public function __construct(
        public readonly string $paymentToken,
        public readonly int $amount,
        public readonly string $currencyCode,
        public readonly ?string $orderId = null,
        public readonly ?string $orderDescription = null,
        public readonly ?string $ipAddress = null,
        public readonly ?BillingDetails $billing = null,
        public readonly ?ThreeDSecureResult $threeDSecure = null,
        /**
         * Whether the gateway should keep this card after charging it.
         *
         * One flag rather than a second call: the sale that takes the money is the same one that
         * stores the card, which is why a declined payment cannot leave a stored card behind.
         */
        public readonly bool $storeCard = false,
    ) {
    }
}
