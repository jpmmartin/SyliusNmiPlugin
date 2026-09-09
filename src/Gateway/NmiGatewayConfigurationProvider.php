<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

final class NmiGatewayConfigurationProvider implements NmiGatewayConfigurationProviderInterface
{
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

        return new NmiGatewayConfiguration(
            paymentMethodCode: $code,
            tokenizationKey: $this->requiredString($config, NmiGatewayFactory::CONFIG_TOKENIZATION_KEY, $code),
            securityKey: $this->requiredString($config, NmiGatewayFactory::CONFIG_SECURITY_KEY, $code),
            useAuthorize: (bool) ($config[NmiGatewayFactory::CONFIG_USE_AUTHORIZE] ?? false),
            // Required like the keys, and for the same reason: a method saved before this field
            // existed has no host, and guessing one would send this account's key to a gateway
            // that may not be its own. The form validated the shape; only the slash is tidied here.
            apiBaseUrl: rtrim($this->requiredString($config, NmiGatewayFactory::CONFIG_API_BASE_URL, $code), '/'),
            // Absent means off, which is what a store that installed this plugin before the
            // setting existed has stored.
            storeCards: (bool) ($config[NmiGatewayFactory::CONFIG_STORE_CARDS] ?? false),
            // Absent means yes. A store that has not answered has not chosen to skip
            // authentication, and reading silence as "no" would make that choice for it.
            authenticateStoredCards: (bool) ($config[NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS] ?? true),
            // Optional, and blank is the same as absent: an operator who cleared the field has
            // stopped this method receiving events, which is a decision the endpoint honours.
            webhookSigningKey: $this->optionalString($config, NmiGatewayFactory::CONFIG_WEBHOOK_SIGNING_KEY),
            // Absent means off: mail nobody asked for is not something to inherit from silence.
            emailCardholder: (bool) ($config[NmiGatewayFactory::CONFIG_EMAIL_CARDHOLDER] ?? false),
            // Absent means off, and on a shared account that default is what keeps the page usable.
            notifyUnknownTransactions: (bool) ($config[NmiGatewayFactory::CONFIG_NOTIFY_UNKNOWN_TRANSACTIONS] ?? false),
        );
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
