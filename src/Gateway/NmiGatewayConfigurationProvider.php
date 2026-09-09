<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class NmiGatewayConfigurationProvider implements NmiGatewayConfigurationProviderInterface
{
    public const PRODUCTION_BASE_URL = 'https://secure.nmi.com';

    public const SANDBOX_BASE_URL = 'https://sandbox.nmi.com';

    private readonly ?string $apiBaseUrlOverride;

    /**
     * @param string|null $apiBaseUrlOverride A reseller (white-label) gateway host that replaces
     *                                        the environment-derived one for every NMI payment method
     */
    public function __construct(?string $apiBaseUrlOverride)
    {
        $normalized = null === $apiBaseUrlOverride ? '' : rtrim(trim($apiBaseUrlOverride), '/');

        $this->apiBaseUrlOverride = '' === $normalized ? null : $normalized;
    }

    public function fromPaymentMethod(PaymentMethodInterface $paymentMethod): NmiGatewayConfiguration
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (null === $gatewayConfig || NmiGatewayFactory::NAME !== $gatewayConfig->getFactoryName()) {
            throw new \InvalidArgumentException(sprintf(
                'Payment method "%s" is not configured with the "%s" gateway factory.',
                $paymentMethod->getCode() ?? '',
                NmiGatewayFactory::NAME,
            ));
        }

        $config = $gatewayConfig->getConfig();
        $code = (string) $paymentMethod->getCode();

        $environment = $this->requiredString($config, NmiGatewayFactory::CONFIG_ENVIRONMENT, $code);
        if (!in_array($environment, NmiGatewayFactory::ENVIRONMENTS, true)) {
            throw NmiGatewayException::configuration(sprintf(
                'Payment method "%s" has an unknown NMI environment "%s".',
                $code,
                $environment,
            ));
        }

        return new NmiGatewayConfiguration(
            tokenizationKey: $this->requiredString($config, NmiGatewayFactory::CONFIG_TOKENIZATION_KEY, $code),
            securityKey: $this->requiredString($config, NmiGatewayFactory::CONFIG_SECURITY_KEY, $code),
            environment: $environment,
            useAuthorize: (bool) ($config[NmiGatewayFactory::CONFIG_USE_AUTHORIZE] ?? false),
            apiBaseUrl: $this->apiBaseUrlOverride ?? $this->baseUrlFor($environment),
            // Absent means off, which is what a store that installed this plugin before the
            // setting existed has stored.
            storeCards: (bool) ($config[NmiGatewayFactory::CONFIG_STORE_CARDS] ?? false),
            // Absent means yes. A store that has not answered has not chosen to skip
            // authentication, and reading silence as "no" would make that choice for it.
            authenticateStoredCards: (bool) ($config[NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS] ?? true),
            // Optional, and blank is the same as absent: an operator who cleared the field has
            // stopped this method receiving events, which is a decision the endpoint honours.
            webhookSigningKey: $this->optionalString($config, NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY),
        );
    }

    private function baseUrlFor(string $environment): string
    {
        return NmiGatewayFactory::ENVIRONMENT_SANDBOX === $environment ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    /** @param array<string, mixed> $config */
    private function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $key, string $paymentMethodCode): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw NmiGatewayException::configuration(sprintf(
                'Payment method "%s" has no "%s" in its NMI gateway configuration.',
                $paymentMethodCode,
                $key,
            ));
        }

        return trim($value);
    }
}
