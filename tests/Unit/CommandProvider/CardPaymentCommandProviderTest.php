<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\CommandProvider;

use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\CommandProvider\CardPaymentCommandProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Uid\Uuid;

final class CardPaymentCommandProviderTest extends TestCase
{
    private CardPaymentCommandProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new CardPaymentCommandProvider();
    }

    #[DataProvider('actionsItHandles')]
    public function testItHandlesBothWaysOfTakingACardPayment(string $action): void
    {
        self::assertTrue($this->provider->supports($this->paymentRequest($action, PaymentRequestInterface::STATE_NEW)));
    }

    /** @return iterable<string, array{string}> */
    public static function actionsItHandles(): iterable
    {
        yield 'charging immediately' => [PaymentRequestInterface::ACTION_CAPTURE];
        yield 'authorising first' => [PaymentRequestInterface::ACTION_AUTHORIZE];
    }

    #[DataProvider('actionsItLeavesAlone')]
    public function testItLeavesOtherActionsToTheirOwnProviders(string $action): void
    {
        self::assertFalse($this->provider->supports($this->paymentRequest($action, PaymentRequestInterface::STATE_NEW)));
    }

    /** @return iterable<string, array{string}> */
    public static function actionsItLeavesAlone(): iterable
    {
        yield 'refund' => [PaymentRequestInterface::ACTION_REFUND];
        yield 'cancel' => [PaymentRequestInterface::ACTION_CANCEL];
        yield 'status' => [PaymentRequestInterface::ACTION_STATUS];
        yield 'notify' => [PaymentRequestInterface::ACTION_NOTIFY];
    }

    /**
     * The phase is the request's state and nothing else: a request that has not started asks
     * the browser for a card, one already in progress charges what came back.
     */
    #[DataProvider('statesAndCommands')]
    public function testTheStateSelectsThePhase(string $state, string $expectedCommand): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, $state);

        $command = $this->provider->provide($paymentRequest);

        self::assertInstanceOf($expectedCommand, $command);
        self::assertSame($paymentRequest->getId(), $command->getHash());
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function statesAndCommands(): iterable
    {
        yield 'not started yet' => [PaymentRequestInterface::STATE_NEW, PrepareCardPayment::class];
        yield 'already in progress' => [PaymentRequestInterface::STATE_PROCESSING, CompleteCardPayment::class];
    }

    /**
     * A finished request is never announced, so this branch should be unreachable. It still
     * must not charge: falling through to the second phase would turn an already-completed
     * payment into a second charge.
     */
    #[DataProvider('finishedStates')]
    public function testAFinishedRequestNeverReachesTheChargingPhase(string $state): void
    {
        $command = $this->provider->provide($this->paymentRequest(PaymentRequestInterface::ACTION_CAPTURE, $state));

        self::assertInstanceOf(PrepareCardPayment::class, $command);
    }

    /** @return iterable<string, array{string}> */
    public static function finishedStates(): iterable
    {
        yield 'completed' => [PaymentRequestInterface::STATE_COMPLETED];
        yield 'failed' => [PaymentRequestInterface::STATE_FAILED];
        yield 'cancelled' => [PaymentRequestInterface::STATE_CANCELLED];
    }

    public function testTheAuthoriseActionGoesThroughTheSameTwoPhases(): void
    {
        self::assertInstanceOf(
            PrepareCardPayment::class,
            $this->provider->provide($this->paymentRequest(PaymentRequestInterface::ACTION_AUTHORIZE, PaymentRequestInterface::STATE_NEW)),
        );
        self::assertInstanceOf(
            CompleteCardPayment::class,
            $this->provider->provide($this->paymentRequest(PaymentRequestInterface::ACTION_AUTHORIZE, PaymentRequestInterface::STATE_PROCESSING)),
        );
    }

    private function paymentRequest(string $action, string $state): PaymentRequest
    {
        $paymentRequest = new PaymentRequest(new Payment(), new PaymentMethod());
        $paymentRequest->setAction($action);
        $paymentRequest->setState($state);

        $reflection = new \ReflectionProperty(PaymentRequest::class, 'hash');
        $reflection->setValue($paymentRequest, Uuid::fromString('0192bb17-0000-7000-8000-000000000001'));

        return $paymentRequest;
    }
}
