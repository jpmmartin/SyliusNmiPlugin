<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\CommandProvider\CancelCommandProvider;
use JpmMartin\SyliusNmiPlugin\CommandProvider\CardPaymentCommandProvider;
use JpmMartin\SyliusNmiPlugin\CommandProvider\RefundCommandProvider;
use JpmMartin\SyliusNmiPlugin\CommandProvider\StatusCommandProvider;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\PaymentBundle\CommandProvider\ServiceProviderAwareCommandProviderInterface;
use Sylius\Bundle\PaymentBundle\Exception\PaymentRequestNotSupportedException;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A gateway is reached through one command provider registered under its factory name. These
 * tests walk that path in the real container, because the wiring is XML tags — nothing in PHP
 * fails when a tag is misspelled, the provider is simply never found.
 */
final class NmiCommandProviderRegistrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheGatewayHasACommandProviderRegisteredUnderItsFactoryName(): void
    {
        self::assertContains(NmiGatewayFactory::NAME, $this->gatewayFactoryProvider()->getCommandProviderIndexes());
        self::assertNotNull($this->gatewayFactoryProvider()->getCommandProvider(NmiGatewayFactory::NAME));
    }

    public function testEveryActionThisReleaseHandlesHasItsOwnProvider(): void
    {
        self::assertEqualsCanonicalizing(
            [
                PaymentRequestInterface::ACTION_CAPTURE,
                PaymentRequestInterface::ACTION_AUTHORIZE,
                PaymentRequestInterface::ACTION_REFUND,
                PaymentRequestInterface::ACTION_CANCEL,
                // Not optional: the pay flow ends by minting a status request, and a gateway
                // that does not answer it turns every successful payment into an error page.
                PaymentRequestInterface::ACTION_STATUS,
            ],
            $this->nmiProvider()->getCommandProviderIndexes(),
        );
    }

    /** @param class-string $expectedProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('actionsAndProviders')]
    public function testEachActionReachesItsProvider(string $action, string $expectedProvider): void
    {
        self::assertInstanceOf($expectedProvider, $this->nmiProvider()->getCommandProvider($action));
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function actionsAndProviders(): iterable
    {
        yield 'charging immediately' => [PaymentRequestInterface::ACTION_CAPTURE, CardPaymentCommandProvider::class];
        yield 'authorising first' => [PaymentRequestInterface::ACTION_AUTHORIZE, CardPaymentCommandProvider::class];
        yield 'refunding' => [PaymentRequestInterface::ACTION_REFUND, RefundCommandProvider::class];
        yield 'voiding' => [PaymentRequestInterface::ACTION_CANCEL, CancelCommandProvider::class];
        yield 'reporting status' => [PaymentRequestInterface::ACTION_STATUS, StatusCommandProvider::class];
    }

    /**
     * The one that matters end to end: a request for an NMI payment method resolves through the
     * platform's own provider to this plugin's command, and the state picks the phase.
     */
    public function testTheStateSelectsThePhaseThroughTheWholeChain(): void
    {
        $new = $this->nmiPaymentRequest(PaymentRequestInterface::STATE_NEW);
        $processing = $this->nmiPaymentRequest(PaymentRequestInterface::STATE_PROCESSING);

        self::assertInstanceOf(PrepareCardPayment::class, $this->gatewayFactoryProvider()->provide($new));
        self::assertInstanceOf(CompleteCardPayment::class, $this->gatewayFactoryProvider()->provide($processing));
    }

    /**
     * Another gateway's request must not reach this plugin. Asked through `provide()` rather
     * than `supports()` on purpose: `supports()` first consults the duplication checker, which
     * queries the database and so needs a persisted payment, while `provide()` is the method
     * the announcer actually calls.
     */
    public function testAnotherGatewaysRequestIsNotHandledHere(): void
    {
        $paymentRequest = $this->nmiPaymentRequest(PaymentRequestInterface::STATE_NEW, 'some_other_gateway');

        $this->expectException(PaymentRequestNotSupportedException::class);

        $this->gatewayFactoryProvider()->provide($paymentRequest);
    }

    /** An action this release does not handle must fail loudly rather than silently do nothing. */
    public function testAnActionThisReleaseDoesNotHandleIsRefused(): void
    {
        $paymentRequest = $this->nmiPaymentRequest(PaymentRequestInterface::STATE_NEW);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_PAYOUT);

        $this->expectException(PaymentRequestNotSupportedException::class);

        $this->gatewayFactoryProvider()->provide($paymentRequest);
    }

    private function gatewayFactoryProvider(): ServiceProviderAwareCommandProviderInterface
    {
        /** @var ServiceProviderAwareCommandProviderInterface $provider */
        $provider = self::getContainer()->get('sylius.command_provider.gateway_factory');

        return $provider;
    }

    private function nmiProvider(): ServiceProviderAwareCommandProviderInterface
    {
        /** @var ServiceProviderAwareCommandProviderInterface $provider */
        $provider = $this->gatewayFactoryProvider()->getCommandProvider(NmiGatewayFactory::NAME);

        return $provider;
    }

    private function nmiPaymentRequest(string $state, string $factoryName = NmiGatewayFactory::NAME): PaymentRequest
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setFactoryName($factoryName);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        $paymentRequest = new PaymentRequest(new Payment(), $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $paymentRequest->setState($state);

        $hash = new \ReflectionProperty(PaymentRequest::class, 'hash');
        $hash->setValue($paymentRequest, Uuid::fromString('0192bb17-0000-7000-8000-000000000002'));

        return $paymentRequest;
    }
}
