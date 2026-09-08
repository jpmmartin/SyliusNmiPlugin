<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/**
 * A sale or an authorisation: where the money comes from, plus the amount and the context.
 *
 * There are two ways to name the money and exactly one of them may be used. A token is a card the
 * shopper just typed; a stored card is one the gateway already holds. The constructor refuses a
 * charge that names both or neither, because either would be a charge nobody can read the intent
 * of — and the gateway would answer the ambiguity with an error rather than a refusal.
 */
final class Charge
{
    public function __construct(
        public readonly ?string $paymentToken,
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
        /** The card the gateway already holds, when this charge re-uses one instead of a token. */
        public readonly ?StoredCard $storedCard = null,
    ) {
        if ((null === $paymentToken) === (null === $storedCard)) {
            throw new \InvalidArgumentException('A charge is paid for by a payment token or by a stored card, and by exactly one of them.');
        }

        // Storing a card the gateway already stores would ask it for a second vault record holding
        // the same card, and it deduplicates nothing — so this is not a redundant flag, it is a
        // duplicate waiting to happen.
        if ($storeCard && null !== $storedCard) {
            throw new \InvalidArgumentException('A stored card cannot be stored again.');
        }
    }
}
