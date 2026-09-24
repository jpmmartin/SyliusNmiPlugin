<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * A held order whose card was kept for renewals, on the order screen: *Cancel* cancels it without
 * asking the gateway for anything, and the card stays kept — whether the renewals go ahead is the
 * store's decision, not the first order's.
 *
 * The admin's actions are reproduced as the platform runs them, through the real dispatcher, as the
 * card-on-file held-order test does.
 */
final class NmiHeldRecurringOrderTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

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

    public function testCancellingAHeldOrderKeptForRenewalsCancelsItAndKeepsTheCard(): void
    {
        [$payment, $credential] = $this->aHeldOrderKeptForRenewals();

        $event = $this->onTheOrderScreen($payment, 'cancel');

        self::assertFalse($event->isStopped(), (string) $event->getMessage());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame([], $this->gateway->operations, 'Something was charged or voided.');
        self::assertFalse($credential->isReleased(), 'Cancelling the first order let the card go.');
        self::assertSame([], $this->queuedPurges());
    }

    /**
     * The case that decides whether such an order can be cancelled at all: a charge was declined, so a
     * sale is on the record, and a void of a declined sale is one the gateway refuses.
     */
    public function testAHeldOrderKeptForRenewalsWhoseChargeWasDeclinedCanStillBeCancelled(): void
    {
        $this->gateway->willFail($this->declined());
        [$payment, $credential] = $this->aHeldOrderKeptForRenewals();
        self::assertTrue($this->onTheOrderScreen($payment, 'complete')->isStopped(), 'The charge was not declined, so the test proves nothing.');
        self::assertSame(['sale'], $this->gateway->operations);

        $event = $this->onTheOrderScreen($payment, 'cancel');

        self::assertFalse($event->isStopped(), (string) $event->getMessage());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertNotContains('void', $this->gateway->operations, 'The declined sale was sent to be voided.');
        self::assertFalse($credential->isReleased());
        self::assertTrue($credential->isUsable());
        self::assertSame([], $this->queuedPurges());
    }

    /**
     * Reproduces an admin action as the platform runs it: the event before, the transition only if
     * nothing stopped it, the flush, then the event after.
     */
    private function onTheOrderScreen(PaymentInterface $payment, string $action): ResourceControllerEvent
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $event = new ResourceControllerEvent($payment);
        $dispatcher->dispatch($event, sprintf('sylius.payment.pre_%s', $action));

        if (!$event->isStopped()) {
            /** @var StateMachineInterface $stateMachine */
            $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
            $stateMachine->apply($payment, PaymentTransitions::GRAPH, 'complete' === $action ? PaymentTransitions::TRANSITION_COMPLETE : PaymentTransitions::TRANSITION_CANCEL);
            $this->manager->flush();
            $dispatcher->dispatch(new ResourceControllerEvent($payment), sprintf('sylius.payment.post_%s', $action));
        }

        return $event;
    }

    /**
     * As the deferred checkout leaves an order whose payment opened recurring charges: the card kept as
     * a recurring credential rather than on file, the payment waiting in processing, nothing charged.
     *
     * @return array{PaymentInterface, NmiRecurringCredentialInterface}
     */
    private function aHeldOrderKeptForRenewals(): array
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $payment = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $payment->setState(PaymentInterface::STATE_PROCESSING);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId('12592792407');
        $credential->setBrand('Visa');
        $credential->setLastFour('1111');
        $credential->setExpiryMonth(10);
        $credential->setExpiryYear(2035);
        $this->manager->persist($credential);
        $this->manager->flush();

        return [$payment, $credential];
    }

    private function declined(): NmiDeclinedException
    {
        return new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction', 'id' => '12592804001', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200',
        ], \JSON_THROW_ON_ERROR)));
    }

    /** @return list<string> the vault references queued for purging */
    private function queuedPurges(): array
    {
        $vaultIds = [];
        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PurgeStoredCard) {
                $vaultIds[] = $message->vaultId;
            }
        }

        return $vaultIds;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }
}
