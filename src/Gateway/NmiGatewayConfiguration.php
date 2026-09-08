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
        public readonly string $tokenizationKey,
        public readonly string $securityKey,
        public readonly string $environment,
        public readonly bool $useAuthorize,
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
    ) {
    }
}
