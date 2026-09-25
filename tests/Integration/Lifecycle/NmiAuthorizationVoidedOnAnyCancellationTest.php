<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\VoidAuthorization;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle\AuthorizesFirst;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * An authorisation is voided whenever its payment is cancelled and the cancellation is saved — not
 * only when an operator voids the payment on the order screen. Sylius cancels an order's payments
 * through the state machine, where the order screen's resource events never fire.
 */
final class NmiAuthorizationVoidedOnAnyCancellationTest extends KernelTestCase
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

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->authorization = (string) random_int(12500000000, 12599999999);
        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->queue()->reset();
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

    /** *Cancelled by the store's own code*, through its order. */
    public function testTheStoresCodeCancellingTheOrderVoidsItsAuthorization(): void
    {
        [$order, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);

        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->manager->flush();
        $this->runTheQueuedWork();

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The order did not take its payment with it.');
        $this->assertTheAuthorizationWasVoidedOnce($this->gateway, $payment, $this->authorization);
    }

    /** *Cancelled by the store's own code*, the payment itself rather than its order. */
    public function testTheStoresCodeCancellingThePaymentVoidsItsAuthorization(): void
    {
        [, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);

        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame([$payment->getId()], $this->queuedVoids());
        $this->runTheQueuedWork();
        $this->assertTheAuthorizationWasVoidedOnce($this->gateway, $payment, $this->authorization);
    }

    /** *A cancellation that is never saved* requests no void. */
    public function testACancellationNeverSavedRequestsNoVoid(): void
    {
        [, $payment] = $this->anAuthorizedOrder($this->authorization);
        $paymentId = $payment->getId();

        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        $this->manager->clear();
        $this->manager->flush();

        $payment = $this->manager->find(Payment::class, $paymentId);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame([], $this->queuedVoids());
    }

    /**
     * *Nothing was authorised* — a payment still awaiting payment, whose declined attempt is on
     * record as an authorisation all the same.
     */
    public function testAPaymentCancelledBeforeAnyApprovalRequestsNoVoid(): void
    {
        [, $payment] = $this->anAuthorizedOrder($this->authorization);
        // As checkout leaves a declined attempt: the answer recorded, the payment still new.
        $payment->setState(PaymentInterface::STATE_NEW);
        $this->manager->flush();

        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame([], $this->queuedVoids());
    }

    /** *Another gateway's payment* is none of NMI's business. */
    public function testAnAuthorizedPaymentOfAnotherGatewayRequestsNoVoid(): void
    {
        [, $payment] = $this->anAuthorizedOrder($this->authorization);
        $gatewayConfig = $payment->getMethod()?->getGatewayConfig();
        self::assertNotNull($gatewayConfig);
        $gatewayConfig->setFactoryName('offline');
        $this->manager->flush();

        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame([], $this->queuedVoids());
    }

    /** @return list<int> the payments whose void was queued */
    private function queuedVoids(): array
    {
        $paymentIds = [];
        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof VoidAuthorization) {
                $paymentIds[] = $message->paymentId;
            }
        }

        return $paymentIds;
    }

    private function stateMachine(): StateMachineInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return $stateMachine;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }
}
