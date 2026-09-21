<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/**
 * A card to verify and keep, with nothing charged: put on file at checkout to be charged later.
 *
 * No amount, deliberately — this is the shape of a request that cannot take money. What it does
 * carry that the account area's vault call does not is the order and the 3-D Secure result: this
 * is the first use of the credential, and the card networks expect the authentication the later,
 * unattended charge will lean on to be established here.
 *
 * No billing address either, for the reason the charge factory gives: handing one to the gateway
 * can trip a store's own address-verification rules, and filling it is the store's decision.
 *
 * @internal
 */
final class CardVerification
{
    public function __construct(
        public readonly string $paymentToken,
        public readonly string $currencyCode,
        public readonly ?string $orderId = null,
        public readonly ?ThreeDSecureResult $threeDSecure = null,
    ) {
    }
}
