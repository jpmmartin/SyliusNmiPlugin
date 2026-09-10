<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Refund;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Refund\MoneyTakingTransactionProvider;
use JpmMartin\SyliusNmiPlugin\Refund\NmiRefundPaymentMethodsProvider;
use JpmMartin\SyliusNmiPlugin\Refund\RefundPaymentTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\RefundPlugin\Checker\OrderRefundingAvailabilityCheckerInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Factory\RefundPaymentFactoryInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Sylius\RefundPlugin\Provider\SupportedRefundPaymentMethodsProvider;
use Sylius\RefundPlugin\StateResolver\RefundPaymentTransitions as RefundPluginTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `sylius/refund-plugin` is optional and stays optional.
 *
 * This plugin refunds from the order screen on its own and needs nothing from that package. What
 * the package adds is its own refund screens, and there NMI is offered for the order that an NMI
 * method paid — once the transaction has settled, which is when the gateway will refund — with
 * nothing on the package's own list of gateways. The refund itself is the other test class's.
 */
final class NmiRefundPluginIntegrationTest extends KernelTestCase
{
    use BuildsARefundableNmiOrder;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /** The *With the optional refund plugin* scenario: the method that took the money, once settled, and nothing configured. */
    public function testTheMethodThatTookTheMoneyIsOfferedOnceSettled(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment] = $this->paidNmiOrder(settled: true);

        $offered = $this->offeredFor($order);

        self::assertSame([$payment->getMethod()], $offered, 'Exactly the method that took the money, and the store configured nothing.');
        self::assertTrue($this->available()($this->number($order)), 'And the refund plugin may refund the order.');
    }

    /** The *Not offered before settlement* scenario. */
    public function testNothingIsOfferedBeforeTheTransactionSettles(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order] = $this->paidNmiOrder(settled: false);

        self::assertSame([], $this->offeredFor($order));
        self::assertFalse($this->available()($this->number($order)), 'Voiding from the order screen is the way until the gateway will refund.');
    }

    /**
     * A second NMI account enabled on the channel is never offered for this order — money goes
     * back through the account that took it — and a stale `nmi` entry a store may still carry on
     * the refund plugin's list adds nothing, because what that entry would add is filtered out.
     */
    public function testAnotherNmiMethodIsNeverOfferedEvenWhenTheListNamesTheGateway(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment] = $this->paidNmiOrder(settled: true);
        $other = $this->anotherMethodOn($order, 'nmi');

        $listNamingNmi = new SupportedRefundPaymentMethodsProvider(
            self::getContainer()->get('sylius.repository.payment_method'),
            ['offline', 'nmi'],
        );
        $offered = (new NmiRefundPaymentMethodsProvider($listNamingNmi, $this->moneyTaking()))->findForOrder($order);

        self::assertContains($payment->getMethod(), $offered);
        self::assertNotContains($other, $offered, 'The other account never took this order\'s money.');
        self::assertCount(1, $offered);
    }

    /** An order another gateway paid is the inner provider's business, and NMI stays out of it. */
    public function testAnOrderPaidAnotherWayIsLeftToTheInnerProvider(): void
    {
        $this->onlyWithTheRefundPlugin();
        $offline = null;
        [$order, $payment] = $this->paidNmiOrder(settled: true);
        $offline = $this->anotherMethodOn($order, 'offline');
        $payment->setMethod($offline);
        $this->manager->flush();

        $offered = $this->offeredFor($order);

        self::assertSame([$offline], $offered, 'The refund plugin\'s own list names offline, and this order is offline\'s.');
        self::assertTrue($this->available()($this->number($order)));
    }

    /**
     * The *Manual completion is refused* scenario, at the workflow: the refund plugin's own
     * `complete` is guarded shut for a refund payment whose method is NMI, the plugin's own
     * transition is open, and an offline refund payment keeps its manual completion.
     */
    public function testAnNmiRefundPaymentIsCompletedByTheGatewayAndNeverByHand(): void
    {
        $this->onlyWithTheRefundPlugin();
        [$order, $payment] = $this->paidNmiOrder(settled: true);
        $offline = $this->anotherMethodOn($order, 'offline');

        /** @var PaymentMethodInterface $nmiMethod */
        $nmiMethod = $payment->getMethod();
        $viaNmi = $this->refundPayment($order, $nmiMethod);
        $viaOffline = $this->refundPayment($order, $offline);

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        self::assertFalse($stateMachine->can($viaNmi, RefundPluginTransitions::GRAPH, RefundPluginTransitions::TRANSITION_COMPLETE), 'No button for this one.');
        self::assertTrue($stateMachine->can($viaNmi, RefundPluginTransitions::GRAPH, RefundPaymentTransitions::TRANSITION_CONFIRM_GATEWAY_REFUND), 'The gateway\'s approval takes the plugin\'s own transition.');
        self::assertTrue($stateMachine->can($viaOffline, RefundPluginTransitions::GRAPH, RefundPluginTransitions::TRANSITION_COMPLETE), 'Money handed back by other means is still completed by hand.');

        $stateMachine->apply($viaNmi, RefundPluginTransitions::GRAPH, RefundPaymentTransitions::TRANSITION_CONFIRM_GATEWAY_REFUND);
        self::assertSame(RefundPaymentInterface::STATE_COMPLETED, $viaNmi->getState());
    }

    /** @return list<PaymentMethodInterface> */
    private function offeredFor(OrderInterface $order): array
    {
        /** @var RefundPaymentMethodsProviderInterface $provider */
        $provider = self::getContainer()->get('sylius_refund.provider.refund_payment_methods');

        return array_values($provider->findForOrder($order));
    }

    private function available(): OrderRefundingAvailabilityCheckerInterface
    {
        /** @var OrderRefundingAvailabilityCheckerInterface $checker */
        $checker = self::getContainer()->get('sylius_refund.checker.order_refunding_availability');

        return $checker;
    }

    private function moneyTaking(): MoneyTakingTransactionProvider
    {
        /** @var MoneyTakingTransactionProvider $provider */
        $provider = self::getContainer()->get('jpm_martin_sylius_nmi.refund.money_taking_transaction');

        return $provider;
    }

    private function refundPayment(OrderInterface $order, PaymentMethodInterface $method): RefundPaymentInterface
    {
        /** @var RefundPaymentFactoryInterface $factory */
        $factory = self::getContainer()->get('sylius_refund.factory.refund_payment');
        $refundPayment = $factory->createWithData($order, 500, 'USD', RefundPaymentInterface::STATE_NEW, $method);
        $this->manager->persist($refundPayment);
        $this->manager->flush();

        return $refundPayment;
    }

    private function number(OrderInterface $order): string
    {
        return (string) $order->getNumber();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
