<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\CapturePayment;
use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PutCardOnFile;
use JpmMartin\SyliusNmiPlugin\CommandProvider\CardPaymentCommandProvider;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one branch taking payment later adds to the routing, and everything it must leave alone.
 *
 * Every combination of the payment's state and the request's state is routed twice — with the
 * setting absent and with it explicitly off — and must land on the same command both times as it
 * did before the setting existed. Only an in-progress request on a method taking payment later
 * goes anywhere new.
 */
final class CardPaymentCommandProviderTakingPaymentLaterTest extends TestCase
{
    private const PAYMENT_STATES = [
        PaymentInterface::STATE_NEW,
        PaymentInterface::STATE_PROCESSING,
        PaymentInterface::STATE_AUTHORIZED,
    ];

    private const REQUEST_STATES = [
        PaymentRequestInterface::STATE_NEW,
        PaymentRequestInterface::STATE_PROCESSING,
        PaymentRequestInterface::STATE_COMPLETED,
        PaymentRequestInterface::STATE_FAILED,
        PaymentRequestInterface::STATE_CANCELLED,
    ];

    /**
     * What each combination routed to before this change, derived from the provider's own rule:
     * an authorised payment is captured, an in-progress request is charged, anything else starts.
     */
    private static function before(string $paymentState, string $requestState): string
    {
        if (PaymentInterface::STATE_AUTHORIZED === $paymentState) {
            return CapturePayment::class;
        }

        return PaymentRequestInterface::STATE_PROCESSING === $requestState ? CompleteCardPayment::class : PrepareCardPayment::class;
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('settingNotTaken')]
    public function testWithoutTheSettingEveryStateRoutesAsItDidBefore(array $config): void
    {
        foreach (self::PAYMENT_STATES as $paymentState) {
            foreach (self::REQUEST_STATES as $requestState) {
                self::assertInstanceOf(
                    self::before($paymentState, $requestState),
                    (new CardPaymentCommandProvider())->provide($this->paymentRequest($paymentState, $requestState, $config)),
                    sprintf('payment %s, request %s', $paymentState, $requestState),
                );
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function settingNotTaken(): iterable
    {
        yield 'absent, as every method stored before the setting existed' => [[]];
        yield 'explicitly off' => [[NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER => false]];
    }

    public function testWithTheSettingAnInProgressRequestPutsTheCardOnFile(): void
    {
        $paymentRequest = $this->paymentRequest(PaymentInterface::STATE_NEW, PaymentRequestInterface::STATE_PROCESSING, [NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER => true]);

        $command = (new CardPaymentCommandProvider())->provide($paymentRequest);

        self::assertInstanceOf(PutCardOnFile::class, $command);
        self::assertSame($paymentRequest->getId(), $command->getHash());
    }

    /** The first phase is shared: the browser needs the same things to collect a card either way. */
    public function testWithTheSettingANewRequestStillStartsTheSameWay(): void
    {
        self::assertInstanceOf(
            PrepareCardPayment::class,
            (new CardPaymentCommandProvider())->provide($this->paymentRequest(PaymentInterface::STATE_NEW, PaymentRequestInterface::STATE_NEW, [NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER => true])),
        );
    }

    /** @param array<string, mixed> $config */
    private function paymentRequest(string $paymentState, string $requestState, array $config): PaymentRequest
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig($config);

        $method = new PaymentMethod();
        $method->setGatewayConfig($gatewayConfig);

        $payment = new Payment();
        $payment->setState($paymentState);

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $paymentRequest->setState($requestState);

        (new \ReflectionProperty(PaymentRequest::class, 'hash'))->setValue($paymentRequest, Uuid::fromString('0192bb17-0000-7000-8000-000000000002'));

        return $paymentRequest;
    }
}
