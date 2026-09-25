<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Integration\CardOnFile;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *A held payment is completed only by an approved charge* — whatever applies the transition. Here,
 * the store's own code, through the platform's state machine.
 */
final class NmiHeldPaymentCompletionTest extends KernelTestCase
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

    /** *Completed by the store's code without a charge.* */
    public function testTheStoresCodeCannotCompleteAPaymentThatHoldsACardOnFile(): void
    {
        $payment = $this->aPaymentHoldingACardOnFile();

        $refused = null;

        try {
            $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        } catch (\Throwable $exception) {
            $refused = $exception;
        }

        self::assertNotNull($refused, 'The payment was completed with nothing charged.');
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertTrue(null !== $this->heldCardOf($payment), 'The card is no longer held.');
        self::assertNotSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState(), 'The order reads paid.');
        self::assertSame([], $this->gateway->operations);
    }

    private function aPaymentHoldingACardOnFile(): PaymentInterface
    {
        $payment = $this->newPaymentRequest()->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $this->aCardOnFileFor($payment);

        return $payment;
    }

    private function stateMachine(): StateMachineInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return $stateMachine;
    }
}
