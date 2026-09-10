<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * Giving the money back.
 *
 * The operator presses one button and the plugin decides how. It cannot look the answer up — the
 * gateway exposes no settled marker and no refundable balance — so it tries the cheaper reversal
 * and reads the refusal.
 */
final class NmiRefundTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const SALE = '12513502276';

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    /** An unsettled sale is taken back with a void, which never reaches the cardholder's statement. */
    public function testAnUnsettledPaymentIsGivenBackWithAVoid(): void
    {
        $payment = $this->completedPayment();
        $this->gateway->willApprove(self::SALE);

        $this->refundFromTheOrderScreen($payment);

        self::assertSame(['void'], $this->gateway->operations);
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_REFUNDED, $payment->getOrder()?->getPaymentState());
        $this->assertRecorded(self::SALE, NmiTransactionInterface::TYPE_VOID);
    }

    /**
     * A settled transaction cannot be voided and the gateway says so in words. The refund follows
     * without the operator having to know the difference — which they could not, since nothing
     * exposes it.
     */
    public function testASettledPaymentFallsBackToARefund(): void
    {
        $payment = $this->completedPayment();
        $this->gateway->willApprove('12513502460');
        $this->gateway->willFailOn('void', NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(400, json_encode([
                'type' => 'inputError',
                'error_code' => 'E_INVALID_TRANS_SPECIFIED',
                'message' => 'Only unsettled transactions can be voided',
            ], \JSON_THROW_ON_ERROR)),
        ));

        $this->refundFromTheOrderScreen($payment);

        self::assertSame(['void', 'refund'], $this->gateway->operations, 'The void is tried first and the refund follows it.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());

        // A refund is a transaction of its own, and it names the one it reverses.
        $refund = $this->transactions()->findOneByTransactionIdAndType('12513502460', NmiTransactionInterface::TYPE_REFUND);
        self::assertNotNull($refund);
        self::assertSame(self::SALE, $refund->getParentTransactionId());
    }

    /**
     * The same outcome as the fallback above, reached without spending a request on it.
     *
     * A webhook told the store the batch settled, so there is nothing to discover: the void would
     * be refused, and asking anyway is a round trip whose answer is already written down. This is
     * what the settlement record buys, and the only thing it buys.
     */
    public function testASettlementTheStoreWasToldAboutSkipsTheVoidEntirely(): void
    {
        $payment = $this->completedPayment();

        $sale = $this->transactions()->findOneByTransactionIdAndType(self::SALE, NmiTransactionInterface::TYPE_SALE);
        self::assertNotNull($sale);
        $sale->setSettledAt(new \DateTimeImmutable('-1 day'));
        $this->manager->flush();

        $this->gateway->willApprove('12513502460');

        $this->refundFromTheOrderScreen($payment);

        self::assertSame(['refund'], $this->gateway->operations, 'No void may be attempted: the store already knows it would be refused.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
    }

    /**
     * And with no record, the previous path still runs — which is what lets a store that never
     * wires webhooks keep working exactly as it did.
     */
    public function testWithNoSettlementRecordedTheGatewayIsStillAsked(): void
    {
        $payment = $this->completedPayment();

        $sale = $this->transactions()->findOneByTransactionIdAndType(self::SALE, NmiTransactionInterface::TYPE_SALE);
        self::assertNotNull($sale);
        self::assertNull($sale->getSettledAt(), 'Nothing told this store anything, which is the ordinary state.');

        $this->gateway->willApprove('12513502461');

        $this->refundFromTheOrderScreen($payment);

        self::assertSame(['void'], $this->gateway->operations, 'The void is tried, exactly as before.');
    }

    /**
     * The *order screen refunds what remains* scenario. What the refund plugin already gave back
     * is subtracted first, and money partly returned rules the void out: the gateway only refunds
     * a settled transaction, so a part having gone back means this one has settled.
     */
    public function testTheOrderScreenRefundsWhatTheRefundPluginLeft(): void
    {
        $payment = $this->completedPayment();
        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved('12513502460', '-5.00'), NmiTransactionInterface::TYPE_REFUND, self::SALE);
        $this->manager->flush();
        $this->gateway->willApprove('12513502461', '-7.99');

        $this->refundFromTheOrderScreen($payment);

        self::assertSame(['refund'], $this->gateway->operations, 'No void: part of the money is already back, so the transaction settled.');
        self::assertSame([799], $this->gateway->refundAmounts, 'The remainder, not the whole transaction.');
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
    }

    /** The *Refunding twice* scenario: nothing left, refused *without asking the gateway* — the store's own record answers it. */
    public function testRefundingTwiceIsRefusedWithoutAskingTheGateway(): void
    {
        $payment = $this->completedPayment();

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved('12513502460', '-12.99'), NmiTransactionInterface::TYPE_REFUND, self::SALE);
        $this->manager->flush();

        $event = $this->refundFromTheOrderScreen($payment);

        self::assertSame([], $this->gateway->operations, 'Nothing may be asked of the gateway.');
        self::assertTrue($event->isStopped());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    /**
     * The one case where trying the other operation would be dangerous. A void that never came
     * back may well have gone through, so falling through to a refund is how money leaves twice.
     */
    public function testAVoidThatNeverAnsweredIsNotRetriedAsARefund(): void
    {
        $payment = $this->completedPayment();
        $this->gateway->willFailOn('void', NmiTransportException::fromInconclusiveStatus(504));

        $event = $this->refundFromTheOrderScreen($payment);

        self::assertSame(['void'], $this->gateway->operations, 'An unanswered void must not become a refund.');
        self::assertTrue($event->isStopped());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function testARefusedRefundLeavesItsReasonOnTheOrder(): void
    {
        $payment = $this->completedPayment();
        $this->gateway->willFail(NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(400, json_encode([
                'type' => 'inputError',
                'error_code' => 'E_INVALID_AMOUNT',
                'message' => 'Refund amount may not exceed the transaction balance',
            ], \JSON_THROW_ON_ERROR)),
        ));

        $this->refundFromTheOrderScreen($payment);

        $refusal = $payment->getDetails()[NmiTransactionRecorder::REFUSAL_DETAILS_KEY] ?? null;
        self::assertIsArray($refusal);
        self::assertSame('Refund amount may not exceed the transaction balance', $refusal['detail']);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    private function completedPayment(): PaymentInterface
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED);

        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $payment->getOrder()?->setPaymentState(OrderPaymentStates::STATE_PAID);

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved(self::SALE), NmiTransactionInterface::TYPE_SALE);

        $this->manager->flush();

        return $payment;
    }

    private function refundFromTheOrderScreen(PaymentInterface $payment): ResourceControllerEvent
    {
        $event = new ResourceControllerEvent($payment);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->dispatch($event, 'sylius.payment.pre_refund');

        if (!$event->isStopped()) {
            /** @var StateMachineInterface $stateMachine */
            $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
            $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }

        $this->manager->flush();

        return $event;
    }

    private function transactions(): NmiTransactionRepositoryInterface
    {
        /** @var NmiTransactionRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        return $repository;
    }

    private function assertRecorded(string $transactionId, string $type): void
    {
        self::assertNotNull($this->transactions()->findOneByTransactionIdAndType($transactionId, $type));
    }

    private function approved(string $transactionId, string $amount = '12.99'): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
    }
}
