<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * Voiding an authorisation the gateway has not settled.
 *
 * The platform ships no void action at all, so the plugin adds one beside the row's own. Like the
 * capture, the gateway is asked before the payment is allowed to move: there is no way out of
 * cancelled, so a refused void would leave an order claiming it returned money that never moved.
 */
final class NmiVoidTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const AUTHORISATION = '12513498102';

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

    public function testVoidingAnUnsettledAuthorisationCancelsThePayment(): void
    {
        $payment = $this->authorisedPayment();
        $this->gateway->willApprove(self::AUTHORISATION);

        $this->voidFromTheOrderScreen($payment);

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame('void', $this->gateway->lastOperation);
        $this->assertRecorded(self::AUTHORISATION, NmiTransactionInterface::TYPE_VOID);
    }

    /**
     * The gateway refuses to void what it has already settled, and says so in words rather than a
     * code. The payment has to stay where it was: cancelled is terminal.
     */
    public function testVoidingASettledTransactionIsRefusedAndChangesNothing(): void
    {
        $payment = $this->authorisedPayment();
        $this->gateway->willFail(NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(400, json_encode([
                'type' => 'inputError',
                'error_code' => 'E_INVALID_TRANS_SPECIFIED',
                'message' => 'Only unsettled transactions can be voided',
            ], \JSON_THROW_ON_ERROR)),
        ));

        $event = $this->voidFromTheOrderScreen($payment);

        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertTrue($event->isStopped(), 'A refused void has to be reported, not swallowed.');

        $refusal = $payment->getDetails()[NmiTransactionRecorder::REFUSAL_DETAILS_KEY] ?? null;
        self::assertIsArray($refusal);
        self::assertSame('Only unsettled transactions can be voided', $refusal['detail']);
    }

    /** Nothing reached the gateway, so there is nothing to take back and the cancel is allowed. */
    public function testCancellingAPaymentWithNoTransactionAsksTheGatewayNothing(): void
    {
        $paymentRequest = $this->newPaymentRequest();

        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $this->manager->flush();

        $event = $this->voidFromTheOrderScreen($payment);

        self::assertFalse($event->isStopped());
        self::assertNull($this->gateway->lastOperation);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
    }

    private function authorisedPayment(): PaymentInterface
    {
        $paymentRequest = $this->newPaymentRequest(
            PaymentRequestInterface::STATE_COMPLETED,
            PaymentRequestInterface::ACTION_AUTHORIZE,
            useAuthorize: true,
        );

        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved(self::AUTHORISATION), NmiTransactionInterface::TYPE_AUTH);

        $this->manager->flush();

        return $payment;
    }

    /** The route this plugin adds runs the platform's own controller, which dispatches this. */
    private function voidFromTheOrderScreen(PaymentInterface $payment): ResourceControllerEvent
    {
        $event = new ResourceControllerEvent($payment);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->dispatch($event, 'sylius.payment.pre_cancel');

        if (!$event->isStopped()) {
            /** @var StateMachineInterface $stateMachine */
            $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
            $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        }

        $this->manager->flush();

        return $event;
    }

    private function assertRecorded(string $transactionId, string $type): void
    {
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        self::assertNotNull($transactions->findOneByTransactionIdAndType($transactionId, $type));
    }

    private function approved(string $transactionId): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'pending',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
            'auth_code' => '123456',
        ], \JSON_THROW_ON_ERROR));
    }
}
