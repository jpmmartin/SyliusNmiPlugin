<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProvider;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;

final class NmiGatewayConfigurationProviderTest extends TestCase
{
    public function testProductionResolvesToTheLiveHostAndSandboxToTheSandboxOne(): void
    {
        $provider = new NmiGatewayConfigurationProvider(null);

        $production = $provider->fromPaymentMethod($this->nmiPaymentMethod(['environment' => 'production']));
        $sandbox = $provider->fromPaymentMethod($this->nmiPaymentMethod(['environment' => 'sandbox']));

        self::assertSame(NmiGatewayConfigurationProvider::PRODUCTION_BASE_URL, $production->apiBaseUrl);
        self::assertSame(NmiGatewayConfigurationProvider::SANDBOX_BASE_URL, $sandbox->apiBaseUrl);
        self::assertSame('tok-public-0123', $production->tokenizationKey);
        self::assertSame('sec-private-4567', $production->securityKey);
        self::assertFalse($production->useAuthorize);
    }

    public function testAResellerHostOverridesTheEnvironmentForEveryMethod(): void
    {
        $provider = new NmiGatewayConfigurationProvider(' https://example.transactiongateway.com/ ');

        self::assertSame(
            'https://example.transactiongateway.com',
            $provider->fromPaymentMethod($this->nmiPaymentMethod(['environment' => 'sandbox']))->apiBaseUrl,
        );
    }

    public function testAnEmptyOverrideIsNoOverride(): void
    {
        $provider = new NmiGatewayConfigurationProvider('');

        self::assertSame(
            NmiGatewayConfigurationProvider::SANDBOX_BASE_URL,
            $provider->fromPaymentMethod($this->nmiPaymentMethod(['environment' => 'sandbox']))->apiBaseUrl,
        );
    }

    public function testTheAuthorizeFlagIsRead(): void
    {
        $configuration = (new NmiGatewayConfigurationProvider(null))
            ->fromPaymentMethod($this->nmiPaymentMethod(['use_authorize' => true]));

        self::assertTrue($configuration->useAuthorize);
    }

    public function testAMissingKeyIsAConfigurationError(): void
    {
        $this->expectException(NmiGatewayException::class);
        $this->expectExceptionMessage('has no "security_key"');

        (new NmiGatewayConfigurationProvider(null))->fromPaymentMethod($this->nmiPaymentMethod(['security_key' => '  ']));
    }

    public function testAnUnknownEnvironmentIsAConfigurationError(): void
    {
        $this->expectException(NmiGatewayException::class);
        $this->expectExceptionMessage('unknown NMI environment');

        (new NmiGatewayConfigurationProvider(null))->fromPaymentMethod($this->nmiPaymentMethod(['environment' => 'staging']));
    }

    public function testAnotherGatewaysPaymentMethodIsRefused(): void
    {
        $paymentMethod = $this->nmiPaymentMethod([]);
        $paymentMethod->getGatewayConfig()?->setFactoryName('offline');

        $this->expectException(\InvalidArgumentException::class);

        (new NmiGatewayConfigurationProvider(null))->fromPaymentMethod($paymentMethod);
    }

    /** @param array<string, mixed> $overrides */
    private function nmiPaymentMethod(array $overrides): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setGatewayName('nmi');
        $gatewayConfig->setConfig(array_merge([
            'tokenization_key' => 'tok-public-0123',
            'security_key' => 'sec-private-4567',
            'environment' => 'production',
        ], $overrides));

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('nmi_card');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
