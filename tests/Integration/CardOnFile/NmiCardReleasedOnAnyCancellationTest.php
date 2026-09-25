<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\CardOnFile;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFile;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * A card on file is let go whenever its payment is cancelled and the cancellation is saved — not
 * only when an operator voids it on the order screen. Sylius cancels an order's payments through
 * the state machine, where the order screen's resource events never fire.
 */
final class NmiCardReleasedOnAnyCancellationTest extends KernelTestCase
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

    /** *Released when the order is cancelled* — the operator cancels the order, not the payment. */
    public function testCancellingTheOrderReleasesTheCardOfItsHeldPayment(): void
    {
        [$order, $payment, $card] = $this->aHeldOrder();

        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The order took its payment with it.');
        $this->assertReleased($card, $payment);
    }

    /** *Released when unpaid orders are cancelled on schedule.* */
    public function testLettingAnUnpaidOrderExpireReleasesTheCardOfItsHeldPayment(): void
    {
        [$order, $payment, $card] = $this->aHeldOrder();
        $order->setCheckoutCompletedAt(new \DateTime('-30 days'));
        $this->manager->flush();
        $paymentId = $payment->getId();

        /** @var UnpaidOrdersStateUpdaterInterface $updater */
        $updater = self::getContainer()->get('sylius.updater.unpaid_orders_state');
        $updater->cancel();

        // The updater clears the entity manager after each batch: read the payment again.
        $payment = $this->manager->find(Payment::class, $paymentId);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The order was not taken for an expired unpaid one.');
        $this->assertReleased(null, $payment);
    }

    /** *Released when the store's code cancels* — the payment itself, through the state machine. */
    public function testTheStoresCodeCancellingThePaymentReleasesItsCard(): void
    {
        [, $payment, $card] = $this->aHeldOrder();

        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        $this->assertReleased($card, $payment);
    }

    /** *A cancellation that is not saved* releases nothing and sends nothing to the gateway. */
    public function testACancellationNeverSavedReleasesNothing(): void
    {
        [, $payment] = $this->aHeldOrder();
        $paymentId = $payment->getId();

        $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_CANCEL);
        $this->manager->clear();
        $this->manager->flush();

        $payment = $this->manager->find(Payment::class, $paymentId);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertTrue(null !== $this->heldCardOf($payment), 'A cancellation that was never saved let the card go.');
        self::assertSame([], $this->queuedPurges());
    }

    /** *A recurring credential is kept* — cancelling the held payment that opened it lets nothing go. */
    public function testCancellingTheOrderOfAHeldPaymentKeptOnACredentialLetsNothingGo(): void
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
        $this->manager->persist($credential);
        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->manager->flush();

        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertFalse($credential->isReleased(), 'Cancelling the first order let the recurring credential go.');
        self::assertSame([], $this->queuedPurges());
    }

    /**
     * The card released by a cancellation is written as every card is: its references encrypted.
     *
     * Read afresh, as a request that cancels an order finds it: loaded for the first time while the
     * cancellation is being saved, after the entity manager has worked out what to write. A card
     * already in memory would be scheduled — and encrypted — regardless, and prove nothing.
     */
    public function testTheReleasedCardIsStillEncryptedAtRest(): void
    {
        [$order, , $card] = $this->aHeldOrder();
        $orderId = $order->getId();
        $cardId = $card->getId();
        $this->manager->clear();
        $order = $this->manager->find(Order::class, $orderId);
        self::assertInstanceOf(OrderInterface::class, $order);

        $this->stateMachine()->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->manager->flush();
        $card = $this->manager->find(NmiCardOnFile::class, $cardId);
        self::assertInstanceOf(NmiCardOnFileInterface::class, $card);

        $row = $this->manager->getConnection()->fetchAssociative(
            'SELECT vault_id, initial_transaction_id, released_at FROM jpm_martin_sylius_nmi_card_on_file WHERE id = :id',
            ['id' => $card->getId()],
        );
        self::assertIsArray($row);
        self::assertNotNull($row['released_at'], 'The release was not written.');
        self::assertStringNotContainsString('1256465022', (string) $row['vault_id'], 'The vault reference was written in plain text.');
        self::assertStringNotContainsString('12584742193', (string) $row['initial_transaction_id'], 'The first transaction was written in plain text.');
        self::assertSame('1256465022', $card->getVaultId(), 'The card in memory reads decrypted again once the flush is over.');
    }

    private function assertReleased(?NmiCardOnFileInterface $card, PaymentInterface $payment): void
    {
        self::assertTrue(null === $this->heldCardOf($payment), 'The card is still held by a cancelled payment.');
        if (null !== $card) {
            self::assertNotNull($card->getReleasedAt());
        }
        self::assertSame(['1256465022'], $this->queuedPurges(), 'Its removal from the gateway\'s vault was not queued once.');
    }

    /** @return array{OrderInterface, PaymentInterface, NmiCardOnFileInterface} */
    private function aHeldOrder(): array
    {
        $paymentRequest = $this->newPaymentRequest();
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $card = $this->aCardOnFileFor($payment);

        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime());
        $this->manager->flush();

        return [$order, $payment, $card];
    }

    private function stateMachine(): StateMachineInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return $stateMachine;
    }

    /** @return list<string> the vault references queued for removal */
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
