<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeCardOnFileHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * A held order on the order screen: *Complete* charges the card on file first, *Cancel* lets it go.
 *
 * The admin's actions are reproduced as the platform runs them — the event before the transition,
 * the transition only if nothing stopped it, the flush, and the event after — through the real
 * dispatcher, so the listeners' registration is exercised along with their behaviour.
 */
final class NmiHeldOrderTest extends KernelTestCase
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
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

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

    /** *Completing charges the card.* And, once that is committed, the card is let go. */
    public function testCompletingAHeldOrderChargesTheCardThenReleasesIt(): void
    {
        $this->gateway->willApprove('12584746059', '109.51');
        [$payment, $card] = $this->aHeldOrder();

        $event = $this->onTheOrderScreen($payment, 'complete');

        self::assertFalse($event->isStopped(), (string) $event->getMessage());
        self::assertSame(['sale'], $this->gateway->operations);
        self::assertSame(StoredCard::INITIATED_BY_MERCHANT, $this->gateway->lastCharge?->storedCard?->initiatedBy);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
        $this->assertRecorded('12584746059', NmiTransactionInterface::TYPE_SALE);

        self::assertNotNull($card->getReleasedAt(), 'The card was not released.');
        self::assertSame(['1256465022'], $this->queuedPurges());
    }

    /** *A declined charge stops the completion.* */
    public function testADeclinedChargeStopsTheCompletionWithTheIssuersReason(): void
    {
        $this->gateway->willFail($this->declined());
        [$payment, $card] = $this->aHeldOrder();

        $event = $this->onTheOrderScreen($payment, 'complete');

        self::assertTrue($event->isStopped());
        self::assertSame('DECLINE', $event->getMessage());
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertNull($card->getReleasedAt(), 'The card has to stay on file for the store to decide what next.');
        self::assertSame([], $this->queuedPurges());
    }

    /** *No payment is marked paid without a charge* — a refusal stops it as a decline does. */
    public function testARefusedChargeStopsTheCompletionTooAndNothingIsSent(): void
    {
        [$payment] = $this->aHeldOrder(status: NmiCardOnFileInterface::STATUS_CLOSED);

        $event = $this->onTheOrderScreen($payment, 'complete');

        self::assertTrue($event->isStopped());
        self::assertSame(ChargeCardOnFileHandler::CLOSED, $event->getMessage());
        self::assertSame([], $this->gateway->operations);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
    }

    /** *Released when the payment is cancelled* — nothing charged, nothing voided. */
    public function testCancellingAHeldOrderReleasesTheCardAndChargesNothing(): void
    {
        [$payment, $card] = $this->aHeldOrder();

        $event = $this->onTheOrderScreen($payment, 'cancel');

        self::assertFalse($event->isStopped(), (string) $event->getMessage());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame([], $this->gateway->operations, 'Something was charged or voided.');
        self::assertNotNull($card->getReleasedAt());
        self::assertSame(['1256465022'], $this->queuedPurges());
    }

    /**
     * The case that decides whether a held order can be cancelled at all: a charge was declined, so a
     * sale is on the record, and a void of a declined sale is one the gateway refuses.
     */
    public function testAHeldOrderWhoseChargeWasDeclinedCanStillBeCancelled(): void
    {
        $this->gateway->willFail($this->declined());
        [$payment, $card] = $this->aHeldOrder();
        self::assertTrue($this->onTheOrderScreen($payment, 'complete')->isStopped());

        $event = $this->onTheOrderScreen($payment, 'cancel');

        self::assertFalse($event->isStopped(), (string) $event->getMessage());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertNotContains('void', $this->gateway->operations, 'The declined sale was sent to be voided.');
        self::assertNotNull($card->getReleasedAt());
    }

    /**
     * *Completing an authorised payment* is capture's business, as it always was. This listener runs
     * ahead of capture on the same event, so it has to step aside rather than charge.
     */
    public function testCompletingAnAuthorisedPaymentStillCapturesAsBefore(): void
    {
        $this->gateway->willApprove('12513506465', '109.51');
        $payment = $this->newPaymentRequest(useAuthorize: true)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_AUTHORIZED);
        /** @var \JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, NmiResponse::fromBody(json_encode([
            'object' => 'transaction', 'id' => '12513506464', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'auth_code' => '123456', 'response' => '1', 'response_text' => 'SUCCESS', 'response_code' => '100',
        ], \JSON_THROW_ON_ERROR)), NmiTransactionInterface::TYPE_AUTH);
        $this->manager->flush();

        $event = $this->onTheOrderScreen($payment, 'complete');

        self::assertFalse($event->isStopped(), (string) $event->getMessage());
        self::assertSame(['capture'], $this->gateway->operations, 'Something other than a capture reached the gateway.');
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
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

    /** @return array{PaymentInterface, NmiCardOnFileInterface} */
    private function aHeldOrder(string $status = NmiCardOnFileInterface::STATUS_ACTIVE): array
    {
        $payment = $this->newPaymentRequest()->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $card = $this->aCardOnFileFor($payment, status: $status);

        return [$payment, $card];
    }

    private function declined(): NmiDeclinedException
    {
        return new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction', 'id' => '12584700003', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
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

    private function assertRecorded(string $transactionId, string $type): void
    {
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        self::assertSame($type, $transactions->findOneByAnyTransactionId($transactionId)?->getType());
    }
}
