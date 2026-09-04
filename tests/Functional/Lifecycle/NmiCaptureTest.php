<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorder;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
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
 * Capturing an authorisation from the order screen.
 *
 * The platform ships no capture trigger, so the plugin hangs one off the action an operator
 * already has. What matters here is the order of events: the gateway is asked *before* the
 * payment is allowed to become completed, because there is no way back from that state.
 */
final class NmiCaptureTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

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

    public function testCompletingAnAuthorisedPaymentClaimsTheMoney(): void
    {
        $payment = $this->authorisedPayment('12513542107');
        $this->gateway->willApprove('12513542107');

        $this->completeFromTheOrderScreen($payment);

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('capture', $this->gateway->lastOperation);

        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        self::assertNotNull(
            $transactions->findOneByTransactionIdAndType('12513542107', NmiTransactionInterface::TYPE_CAPTURE),
            'The capture must be recorded against the authorisation it claims.',
        );
    }

    /**
     * The clause that decides where this listener has to live. A payment marked completed cannot
     * be moved back, so a refusal has to stop the transition rather than follow it.
     */
    public function testARefusedCaptureLeavesThePaymentUncaptured(): void
    {
        $payment = $this->authorisedPayment('12513542107');
        $this->gateway->willFail(NmiGatewayException::fromHttpStatus(400));

        $event = $this->completeFromTheOrderScreen($payment);

        self::assertSame(
            PaymentInterface::STATE_AUTHORIZED,
            $payment->getState(),
            'A capture the gateway refused must leave the payment exactly where it was.',
        );
        self::assertTrue($event->isStopped(), 'The operator has to be told the capture was refused.');
        self::assertNotSame('', $event->getMessage());
    }

    /**
     * The gateway has no code for an authorisation it will no longer settle — that was established
     * against the sandbox and is why nothing here recognises one. What the operator gets is the
     * gateway's own sentence, and it has to survive the redirect: a flash is gone on the next
     * click, so the reason is written onto the payment, which the order screen shows.
     */
    public function testAnExpiredAuthorisationLeavesItsReasonOnTheOrder(): void
    {
        $payment = $this->authorisedPayment('12513542107');
        $this->gateway->willFail(NmiGatewayException::fromError(
            NmiErrorResponse::fromBody(400, json_encode([
                'type' => 'inputError',
                'error_code' => 'E_INVALID_TRANS_SPECIFIED',
                'message' => 'A capture requires that the existing transaction be an AUTH',
                'ref_id' => '186208784',
            ], \JSON_THROW_ON_ERROR)),
        ));

        $event = $this->completeFromTheOrderScreen($payment);

        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertTrue($event->isStopped());

        $refusal = $payment->getDetails()[NmiTransactionRecorder::REFUSAL_DETAILS_KEY] ?? null;
        self::assertIsArray($refusal, 'The reason has to outlive the flash message.');
        self::assertSame(
            'A capture requires that the existing transaction be an AUTH',
            $refusal['detail'],
            "The operator gets the gateway's own sentence, not this plugin's paraphrase of it.",
        );
    }

    /** A payment with no authorisation on record has nothing to claim, and says so. */
    public function testAPaymentWithNoAuthorisationIsNotCaptured(): void
    {
        $payment = $this->authorisedPayment(null);

        $this->completeFromTheOrderScreen($payment);

        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertNull($this->gateway->lastOperation, 'There was nothing to ask the gateway for.');
    }

    private function authorisedPayment(?string $authorisationId): PaymentInterface
    {
        $paymentRequest = $this->newPaymentRequest(
            PaymentRequestInterface::STATE_COMPLETED,
            PaymentRequestInterface::ACTION_AUTHORIZE,
            useAuthorize: true,
        );

        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);

        if (null !== $authorisationId) {
            /** @var NmiTransactionRecorderInterface $recorder */
            $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
            $recorder->record($payment, $this->approved($authorisationId), NmiTransactionInterface::TYPE_AUTH);
        }

        $this->manager->flush();

        return $payment;
    }

    /**
     * Dispatches the event the admin's complete action dispatches, through the real dispatcher, so
     * the listener's registration is exercised along with its behaviour. The admin form itself is
     * a CSRF-protected round trip that says nothing more about this plugin.
     */
    private function completeFromTheOrderScreen(PaymentInterface $payment): ResourceControllerEvent
    {
        $event = new ResourceControllerEvent($payment);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->dispatch($event, 'sylius.payment.pre_complete');

        // The admin controller applies the transition only when nothing stopped the event.
        if (!$event->isStopped()) {
            /** @var StateMachineInterface $stateMachine */
            $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
            $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        }

        $this->manager->flush();

        return $event;
    }

    private function approved(string $transactionId): \JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse
    {
        return \JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse::fromBody(json_encode([
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
