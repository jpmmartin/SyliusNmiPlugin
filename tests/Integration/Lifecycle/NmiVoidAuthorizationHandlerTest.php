<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\VoidAuthorization;
use JpmMartin\SyliusNmiPlugin\CommandHandler\VoidAuthorizationHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle\AuthorizesFirst;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * What the worker does with a queued void: ask the gateway once, record its answer on the payment,
 * and leave to the transport only what asking again could change.
 */
final class NmiVoidAuthorizationHandlerTest extends KernelTestCase
{
    use AuthorizesFirst;
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    /** Fresh for every test: the record keeps one row per identifier and type, whoever wrote it. */
    private string $authorization;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    private TestHandler $log;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->authorization = (string) random_int(12500000000, 12599999999);
        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->log = new TestHandler();
        $this->logger()->pushHandler($this->log);

        /** @var InMemoryTransport $queue */
        $queue = self::getContainer()->get('messenger.transport.main');
        $queue->reset();
        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->logger()->popHandler();
        $this->manager->rollback();

        parent::tearDown();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    public function testAnAcceptedVoidIsRecordedAgainstThePayment(): void
    {
        $payment = $this->aCancelledPayment();
        $this->gateway->willApprove($this->authorization);

        $this->handle($payment);

        $this->assertTheAuthorizationWasVoidedOnce($this->gateway, $payment, $this->authorization);
        self::assertSame(NmiTransactionInterface::TYPE_VOID, $payment->getDetails()[NmiTransactionRecorder::DETAILS_KEY]['type'] ?? null);
    }

    public function testARefusedVoidIsRecordedOnThePaymentAndNotTriedAgain(): void
    {
        $payment = $this->aCancelledPayment();
        $this->gateway->willFail(NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(400, json_encode([
                'type' => 'inputError',
                'error_code' => 'E_INVALID_TRANS_SPECIFIED',
                'message' => 'Transaction can not be voided',
            ], \JSON_THROW_ON_ERROR)),
        ));

        $this->handle($payment);

        self::assertSame([$this->authorization], $this->gateway->voidedTransactionIds);
        $refusal = $payment->getDetails()[NmiTransactionRecorder::REFUSAL_DETAILS_KEY] ?? null;
        self::assertIsArray($refusal, 'The refusal is not recorded on the payment.');
        self::assertSame(VoidAuthorizationHandler::REFUSED, $refusal['message_key']);
        self::assertSame('Transaction can not be voided', $refusal['detail']);
        self::assertNull($this->transactions()->findOneByTransactionIdAndType($this->authorization, NmiTransactionInterface::TYPE_VOID));
        self::assertTrue($this->log->hasWarningThatContains('NMI refused to void'));
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
    }

    public function testAGatewayThatDoesNotAnswerIsLeftToTheTransportToTryAgain(): void
    {
        $payment = $this->aCancelledPayment();
        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));

        try {
            $this->handle($payment);
            self::fail('The transport was not told the void failed.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(NmiTransportException::class, $exception->getPrevious());
        }

        self::assertArrayNotHasKey(NmiTransactionRecorder::REFUSAL_DETAILS_KEY, $payment->getDetails(), 'An unanswered void was recorded as a refusal.');
    }

    public function testAPaymentThatIsNotCancelledIsNeverVoided(): void
    {
        [, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);

        try {
            $this->handle($payment);
            self::fail('The transport was not told to try again.');
        } catch (HandlerFailedException) {
        }

        self::assertSame([], $this->gateway->voidedTransactionIds, 'A live order\'s authorisation was voided.');
    }

    public function testAnAuthorizationAlreadyVoidedIsNotVoidedAgain(): void
    {
        $payment = $this->aCancelledPayment();
        $this->gateway->willApprove($this->authorization);
        $this->handle($payment);

        $this->handle($payment);

        self::assertSame([$this->authorization], $this->gateway->voidedTransactionIds, 'The gateway was asked twice.');
    }

    public function testAPaymentThatNoLongerExistsIsReportedAndNotRetried(): void
    {
        $this->gateway->willApprove($this->authorization);

        $this->bus()->dispatch(new Envelope(new VoidAuthorization(2147483000), [new ReceivedStamp('main')]));

        self::assertSame([], $this->gateway->operations);
        self::assertTrue($this->log->hasWarningThatContains('the payment no longer exists'));
    }

    public function testAPaymentMethodThatCannotBeUsedIsReportedAndNotRetried(): void
    {
        $payment = $this->aCancelledPayment();
        $gatewayConfig = $payment->getMethod()?->getGatewayConfig();
        self::assertNotNull($gatewayConfig);
        $config = $gatewayConfig->getConfig();
        unset($config[NmiGatewayFactory::CONFIG_SECURITY_KEY]);
        $gatewayConfig->setConfig($config);
        $this->manager->flush();

        $this->handle($payment);

        self::assertSame([], $this->gateway->operations);
        self::assertTrue($this->log->hasWarningThatContains('its payment method is not usable'));
    }

    private function aCancelledPayment(): PaymentInterface
    {
        [, $payment] = $this->anAuthorizedOrder($this->authorization);
        $payment->setState(PaymentInterface::STATE_CANCELLED);
        $this->manager->flush();

        return $payment;
    }

    private function handle(PaymentInterface $payment): void
    {
        $this->bus()->dispatch(new Envelope(new VoidAuthorization((int) $payment->getId()), [new ReceivedStamp('main')]));
    }

    private function bus(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('sylius.event_bus');

        return $bus;
    }

    private function transactions(): NmiTransactionRepositoryInterface
    {
        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        return $transactions;
    }

    private function logger(): Logger
    {
        /** @var Logger $logger */
        $logger = self::getContainer()->get('logger');

        return $logger;
    }
}
