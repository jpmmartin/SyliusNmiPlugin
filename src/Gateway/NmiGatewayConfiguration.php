<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

/**
 * Everything the plugin needs to talk to the gateway on behalf of one payment method,
 * resolved once from the method's decrypted gateway configuration.
 */
final class NmiGatewayConfiguration
{
    public function __construct(
        /**
         * The code of the payment method this was resolved from. Carried so that a refusal by the
         * gateway can be logged against the method an operator would open, not against a host.
         */
        public readonly string $paymentMethodCode,
        public readonly string $tokenizationKey,
        public readonly string $securityKey,
        public readonly bool $useAuthorize,
        /** Configured on the method, `https` and host only; the trailing slash is already gone. */
        public readonly string $apiBaseUrl,
        /** Last and defaulted only because PHP will not take an optional argument before a required one. */
        public readonly bool $storeCards = false,
        /**
         * Whether paying with a stored card runs 3-D Secure again.
         *
         * Defaults to true, and the default matters: a store that never answered the question gets
         * the authenticated payment rather than the one nobody checked.
         */
        public readonly bool $authenticateStoredCards = true,
        /**
         * The key inbound deliveries are signed with, or null when this method receives none.
         *
         * Null is not a degraded mode: with no key there is nothing to verify a delivery against,
         * so the endpoint refuses every one of them rather than trusting what it cannot check.
         */
        public readonly ?string $webhookSigningKey = null,
        /**
         * Whether a card the issuer closed or flagged earns the shopper an email.
         *
         * Defaults to off. Outbound mail about something the store did not do is a decision an
         * operator makes, not one they inherit.
         */
        public readonly bool $emailCardholder = false,
        /**
         * Whether an unknown transaction is worth telling an operator about.
         *
         * Defaults to off, because on a shared gateway account every other store's activity
         * arrives here and would fill the page with things nobody can act on.
         */
        public readonly bool $notifyUnknownTransactions = false,
    ) {
    }
}
