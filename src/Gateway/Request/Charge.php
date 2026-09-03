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
    ) {
    }
}
