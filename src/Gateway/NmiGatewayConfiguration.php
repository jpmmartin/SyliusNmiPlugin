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
    ) {
    }
}
