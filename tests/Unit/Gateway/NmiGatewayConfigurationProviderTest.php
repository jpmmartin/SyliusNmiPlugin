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
    /** The host is whatever the method says it is: NMI's own, or a reseller's. */
    public function testTheHostComesFromTheMethod(): void
    {
        $provider = new NmiGatewayConfigurationProvider();

        $live = $provider->fromPaymentMethod($this->nmiPaymentMethod([NmiGatewayFactory::CONFIG_API_BASE_URL => NmiGatewayFactory::NMI_PRODUCTION_HOST]));
        $reseller = $provider->fromPaymentMethod($this->nmiPaymentMethod([NmiGatewayFactory::CONFIG_API_BASE_URL => 'https://example.transactiongateway.com']));

        self::assertSame(NmiGatewayFactory::NMI_PRODUCTION_HOST, $live->apiBaseUrl);
        self::assertSame('https://example.transactiongateway.com', $reseller->apiBaseUrl);
        self::assertSame('nmi_card', $live->paymentMethodCode);
        self::assertSame('tok-public-0123', $live->tokenizationKey);
        self::assertSame('sec-private-4567', $live->securityKey);
        self::assertFalse($live->useAuthorize);
    }

    /** Whitespace and a trailing slash are tidied, so that the path joins cleanly. */
    public function testTheHostIsNormalised(): void
    {
        $configuration = (new NmiGatewayConfigurationProvider())
            ->fromPaymentMethod($this->nmiPaymentMethod([NmiGatewayFactory::CONFIG_API_BASE_URL => ' https://example.transactiongateway.com/ ']));

        self::assertSame('https://example.transactiongateway.com', $configuration->apiBaseUrl);
    }

    /**
     * The *method saved before the host existed* scenario: no host, no request, and a message
     * that names the method and the field an operator has to fill in.
     */
    public function testAMethodWithoutAHostIsAConfigurationErrorNamingTheField(): void
    {
        $this->expectException(NmiGatewayException::class);
        $this->expectExceptionMessage('Payment method "nmi_card" has no "api_base_url"');

        (new NmiGatewayConfigurationProvider())->fromPaymentMethod($this->nmiPaymentMethod([NmiGatewayFactory::CONFIG_API_BASE_URL => null]));
    }

    /**
     * Silence is yes. A store that never answered the authentication question has not chosen to
     * skip it, and reading an absent key as false would make that choice on its behalf.
     */
    public function testStoredCardAuthenticationIsOnUnlessTurnedOff(): void
    {
        $provider = new NmiGatewayConfigurationProvider();

        self::assertTrue(
            $provider->fromPaymentMethod($this->nmiPaymentMethod([]))->authenticateStoredCards,
            'A store that never answered has not chosen to skip authentication.',
        );
        self::assertTrue($provider->fromPaymentMethod($this->nmiPaymentMethod([
            NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS => true,
        ]))->authenticateStoredCards);
        self::assertFalse($provider->fromPaymentMethod($this->nmiPaymentMethod([
            NmiGatewayFactory::CONFIG_AUTHENTICATE_STORED_CARDS => false,
        ]))->authenticateStoredCards);
    }

    public function testTheAuthorizeFlagIsRead(): void
    {
        $configuration = (new NmiGatewayConfigurationProvider())
            ->fromPaymentMethod($this->nmiPaymentMethod(['use_authorize' => true]));

        self::assertTrue($configuration->useAuthorize);
    }

    public function testAMissingKeyIsAConfigurationError(): void
    {
        $this->expectException(NmiGatewayException::class);
        $this->expectExceptionMessage('has no "security_key"');

        (new NmiGatewayConfigurationProvider())->fromPaymentMethod($this->nmiPaymentMethod(['security_key' => '  ']));
    }

    public function testAnotherGatewaysPaymentMethodIsRefused(): void
    {
        $paymentMethod = $this->nmiPaymentMethod([]);
        $paymentMethod->getGatewayConfig()?->setFactoryName('offline');

        $this->expectException(\InvalidArgumentException::class);

        (new NmiGatewayConfigurationProvider())->fromPaymentMethod($paymentMethod);
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
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiGatewayFactory::NMI_SANDBOX_HOST,
        ], $overrides));

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('nmi_card');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
