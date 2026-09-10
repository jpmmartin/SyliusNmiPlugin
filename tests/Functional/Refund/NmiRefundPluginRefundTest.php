<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Refund;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiErrorResponse;
use JpmMartin\SyliusNmiPlugin\Refund\Exception\RefundNotPerformed;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\RefundPlugin\Entity\CreditMemoInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * A refund made from the refund plugin's screens is a real refund.
 *
 * Driven through the refund plugin's own command, the way its screen does it, so that what is
 * asserted is the whole chain: its credit memo, its refund payment, this plugin's request to the
 * gateway, and — when the gateway says no — the refund plugin's transaction undoing all of it.
 */
final class NmiRefundPluginRefundTest extends KernelTestCase
{
    use BuildsARefundableNmiOrder;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        // The refund plugin's command runs in a transaction of its own, inside this test's. A
        // refusal must roll that inner one back and leave the outer one usable, which nested
        // transactions only do as savepoints.
        $this->manager->getConnection()->setNestTransactionsWithSavepoints(true);
        $this->manager->beginTransaction();

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        // The operator's session, where a refusal's reason is put: the refund plugin itself shows
        // a handler's failure as one generic sentence.
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        /** @var RequestStack $requestStack */
        $requestStack = $container->get('request_stack');
        $requestStack->push($request);
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /** The *Partial refund through the refund plugin* scenario. */
    public function testAPartOfTheOrderIsRefundedAtTheGatewayRecordedAndCompleted(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment, $adjustmentId] = $this->paidNmiOrder(settled: true);
        $this->gateway->willApprove('12513502460', '-5.00');

        $this->refundThroughTheRefundPlugin($order, $adjustmentId, 500, $this->methodOf($payment));

        self::assertSame(['refund'], $this->gateway->operations, 'A refund, against the transaction that took the money — never a void.');
        self::assertSame([500], $this->gateway->refundAmounts, 'For the amount the operator asked, not the whole transaction.');

        $refund = $this->transactions()->findOneByTransactionIdAndType('12513502460', NmiTransactionInterface::TYPE_REFUND);
        self::assertNotNull($refund, 'Recorded, with the gateway\'s own reference.');
        self::assertSame(self::SALE, $refund->getParentTransactionId());
        self::assertSame(500, abs((int) $refund->getAmount()));

        $refundPayments = $this->refundPaymentsOf($order);
        self::assertCount(1, $refundPayments);
        self::assertSame(RefundPaymentInterface::STATE_COMPLETED, $refundPayments[0]->getState(), 'Completed by the gateway\'s approval, by nothing else.');
        self::assertSame(500, $refundPayments[0]->getAmount());

        self::assertSame(OrderPaymentStates::STATE_PARTIALLY_REFUNDED, $order->getPaymentState());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState(), 'A part leaves the payment as it is.');
        self::assertCount(1, $this->creditMemosOf($order));
    }

    /** The whole amount given back through the refund plugin is the same fact the order screen records. */
    public function testTheWholeAmountRefundedThereMovesThePaymentToRefundedToo(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment, $adjustmentId] = $this->paidNmiOrder(settled: true);
        $this->gateway->willApprove('12513502461', '-12.99');

        $this->refundThroughTheRefundPlugin($order, $adjustmentId, self::AMOUNT, $this->methodOf($payment));

        self::assertSame([self::AMOUNT], $this->gateway->refundAmounts);
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState(), 'So the order screen stops offering a refund of money that is gone.');
        self::assertSame(OrderPaymentStates::STATE_REFUNDED, $order->getPaymentState());
        self::assertSame(RefundPaymentInterface::STATE_COMPLETED, $this->refundPaymentsOf($order)[0]->getState());
    }

    /** The *A further partial refund* scenario: its own refund, for its own amount, and the sum never exceeds the transaction. */
    public function testAFurtherPartIsItsOwnRefund(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment, $adjustmentId] = $this->paidNmiOrder(settled: true);
        $method = $this->methodOf($payment);

        $this->gateway->willApprove('12513502460', '-5.00');
        $this->refundThroughTheRefundPlugin($order, $adjustmentId, 500, $method);
        $this->gateway->willApprove('12513502462', '-7.99');
        $this->refundThroughTheRefundPlugin($order, $adjustmentId, 799, $method);

        self::assertSame(['refund', 'refund'], $this->gateway->operations);
        self::assertSame([500, 799], $this->gateway->refundAmounts);
        self::assertCount(2, $this->refundPaymentsOf($order));

        $sale = $this->transactions()->findOneByTransactionIdAndType(self::SALE, NmiTransactionInterface::TYPE_SALE);
        self::assertNotNull($sale);
        self::assertSame(0, self::getContainer()->get('jpm_martin_sylius_nmi.refund.money_taking_transaction')->remainingOn($sale), 'Nothing is left, and the order screen would now refuse.');
        self::assertSame(OrderPaymentStates::STATE_REFUNDED, $order->getPaymentState());
    }

    /** The *gateway refuses the refund* scenario: nothing half done survives, and the operator hears why. */
    public function testARefusalUndoesTheCreditMemoAndTellsTheOperatorTheReason(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment, $adjustmentId] = $this->paidNmiOrder(settled: true);
        $this->gateway->willFailOn('refund', NmiGatewayException::fromError(NmiErrorResponse::fromBody(400, json_encode([
            'type' => 'inputError',
            'error_code' => 'E_INVALID_AMOUNT',
            'message' => 'Refund amount may not exceed the transaction balance',
        ], \JSON_THROW_ON_ERROR))));

        try {
            $this->refundThroughTheRefundPlugin($order, $adjustmentId, 500, $this->methodOf($payment));
            self::fail('The refund plugin\'s command must fail when the gateway refuses.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(RefundNotPerformed::class, $this->rootOf($exception));
        }

        self::assertSame(['refund'], $this->gateway->operations, 'The gateway was asked once.');

        // Read back from the database rather than from the objects in hand: the rollback is the
        // refund plugin's transaction, and the objects remember what it undid.
        $this->manager->clear();
        $order = $this->manager->find(OrderInterface::class, $order->getId());
        self::assertNotNull($order);
        self::assertSame([], $this->refundPaymentsOf($order), 'No refund payment survives the refusal.');
        self::assertSame([], $this->creditMemosOf($order), 'And no credit memo.');
        self::assertNull($this->transactions()->findOneByTransactionIdAndType('12513502460', NmiTransactionInterface::TYPE_REFUND));
        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());

        $flashes = $this->session->getFlashBag()->peek('error');
        self::assertNotEmpty($flashes, 'The reason reaches the operator\'s session.');
        self::assertStringContainsString('Refund amount may not exceed the transaction balance', (string) $flashes[0]);
    }

    /** A refund the operator routes through an offline method is none of the gateway's business. */
    public function testARefundThroughAnotherMethodIsLeftAlone(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, , $adjustmentId] = $this->paidNmiOrder(settled: true);
        $offline = $this->anotherMethodOn($order, 'offline');

        $this->refundThroughTheRefundPlugin($order, $adjustmentId, 500, $offline);

        self::assertSame([], $this->gateway->operations, 'Nothing is asked of the gateway.');
        $refundPayments = $this->refundPaymentsOf($order);
        self::assertCount(1, $refundPayments);
        self::assertSame(RefundPaymentInterface::STATE_NEW, $refundPayments[0]->getState(), 'Left for the operator to complete by hand, as the refund plugin intends.');
    }

    /** @return list<RefundPaymentInterface> */
    private function refundPaymentsOf(OrderInterface $order): array
    {
        /** @var list<RefundPaymentInterface> $refundPayments */
        $refundPayments = self::getContainer()->get('sylius_refund.repository.refund_payment')->findBy(['order' => $order], ['id' => 'ASC']);

        return $refundPayments;
    }

    /** @return list<CreditMemoInterface> */
    private function creditMemosOf(OrderInterface $order): array
    {
        /** @var list<CreditMemoInterface> $creditMemos */
        $creditMemos = self::getContainer()->get('sylius_refund.repository.credit_memo')->findBy(['order' => $order]);

        return $creditMemos;
    }

    private function methodOf(PaymentInterface $payment): \Sylius\Component\Core\Model\PaymentMethodInterface
    {
        $method = $payment->getMethod();
        self::assertInstanceOf(\Sylius\Component\Core\Model\PaymentMethodInterface::class, $method);

        return $method;
    }

    private function rootOf(\Throwable $exception): \Throwable
    {
        while ($exception instanceof HandlerFailedException && null !== $exception->getPrevious()) {
            $exception = $exception->getPrevious();
        }

        return $exception;
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
