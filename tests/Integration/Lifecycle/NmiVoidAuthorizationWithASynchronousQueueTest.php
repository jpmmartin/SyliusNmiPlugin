<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle\AuthorizesFirst;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *A store whose queue is synchronous* handles the void inside the flush that saved the
 * cancellation. The void still goes out and the cancellation is still saved; nothing is written and
 * nothing is thrown, and the log says why the void is not on record.
 *
 * `main` is replaced by Symfony's own synchronous transport before anything reaches it, which is
 * what a store that pointed it at `sync://` runs.
 */
final class NmiVoidAuthorizationWithASynchronousQueueTest extends KernelTestCase
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

        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('messenger.routable_message_bus');
        self::getContainer()->set('messenger.transport.main', new SyncTransport($bus));

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->authorization = (string) random_int(12500000000, 12599999999);
        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->log = new TestHandler();
        $this->logger()->pushHandler($this->log);

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

    public function testTheVoidIsSentAndNotRecorded(): void
    {
        [$order, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);

        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame([$this->authorization], $this->gateway->voidedTransactionIds, 'The void did not go out.');
        self::assertNull($this->transactions()->findOneByTransactionIdAndType($this->authorization, NmiTransactionInterface::TYPE_VOID));
        self::assertTrue($this->log->hasWarningThatContains('the queue that carries the void is synchronous'));
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reloaded($payment)->getState(), 'The cancellation was not saved.');
    }

    public function testAGatewayThatDoesNotAnswerFailsNothing(): void
    {
        [$order, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));

        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame([$this->authorization], $this->gateway->voidedTransactionIds);
        self::assertTrue($this->log->hasWarningThatContains('could not record why'));
        $payment = $this->reloaded($payment);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The cancellation was not saved.');
        self::assertArrayNotHasKey(NmiTransactionRecorder::REFUSAL_DETAILS_KEY, $payment->getDetails());
    }

    private function reloaded(PaymentInterface $payment): PaymentInterface
    {
        $id = $payment->getId();
        $this->manager->clear();
        $payment = $this->manager->find(Payment::class, $id);
        self::assertInstanceOf(PaymentInterface::class, $payment);

        return $payment;
    }

    private function stateMachine(): StateMachineInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return $stateMachine;
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
