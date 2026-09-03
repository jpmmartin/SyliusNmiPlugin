<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Gateway;

use JpmMartin\SyliusNmiPlugin\Form\Type\NmiGatewayConfigurationType;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\PaymentBundle\Provider\DefaultActionProviderInterface;
use Sylius\Bundle\ResourceBundle\Form\Registry\FormTypeRegistryInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gateway factory is a string asserted into existence by tagging the form type. These tests
 * pin down the three things Sylius derives from that tag, without touching the database.
 */
final class NmiGatewayFactoryRegistrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testNmiIsListedAmongTheGatewayFactories(): void
    {
        /** @var array<string, string> $factories */
        $factories = self::getContainer()->getParameter('sylius.gateway_factories');

        self::assertArrayHasKey(NmiGatewayFactory::NAME, $factories);
        self::assertSame('jpm_martin_sylius_nmi.gateway_factory.nmi', $factories[NmiGatewayFactory::NAME]);
    }

    public function testTheConfigurationFormTypeIsRegisteredForTheFactory(): void
    {
        /** @var FormTypeRegistryInterface $registry */
        $registry = self::getContainer()->get('sylius.form_registry.payment_gateway_config');

        self::assertTrue($registry->has('gateway_config', NmiGatewayFactory::NAME));
        self::assertSame(NmiGatewayConfigurationType::class, $registry->get('gateway_config', NmiGatewayFactory::NAME));
    }

    public function testChargingImmediatelyIsTheDefaultAction(): void
    {
        $action = $this->defaultActionProvider()->getActionFromPaymentMethod($this->paymentMethodWithConfig([]));

        self::assertSame(PaymentRequestInterface::ACTION_CAPTURE, $action);
    }

    public function testTheAuthorizeFlagSelectsTheAuthorizeAction(): void
    {
        $action = $this->defaultActionProvider()->getActionFromPaymentMethod(
            $this->paymentMethodWithConfig([NmiGatewayFactory::CONFIG_USE_AUTHORIZE => true]),
        );

        self::assertSame(PaymentRequestInterface::ACTION_AUTHORIZE, $action);
    }

    private function defaultActionProvider(): DefaultActionProviderInterface
    {
        /** @var DefaultActionProviderInterface $provider */
        $provider = self::getContainer()->get('sylius.provider.payment_request.default_action');

        return $provider;
    }

    /** @param array<string, mixed> $config */
    private function paymentMethodWithConfig(array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setGatewayName('nmi');
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('nmi');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
