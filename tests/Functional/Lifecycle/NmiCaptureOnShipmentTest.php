<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * Claiming an authorisation when the goods go out, and — the part that matters — claiming it only
 * once however many parcels an order ships in.
 */
final class NmiCaptureOnShipmentTest extends KernelTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const AUTHORISATION = '12513542107';

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

    public function testShippingAnOrderClaimsItsAuthorisation(): void
    {
        $payment = $this->authorisedPayment();
        $this->gateway->willApprove(self::AUTHORISATION);

        $this->ship($this->shipmentFor($payment));

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame('capture', $this->gateway->lastOperation);
    }

    /** The scenario this listener exists to get right. */
    public function testAnOrderShippedInTwoParcelsIsCapturedOnce(): void
    {
        $payment = $this->authorisedPayment();
        $this->gateway->willApprove(self::AUTHORISATION);

        $first = $this->shipmentFor($payment);
        $second = $this->shipmentFor($payment);

        $this->ship($first);
        $this->gateway->lastOperation = null;

        $this->ship($second);

        self::assertNull($this->gateway->lastOperation, 'The second parcel must not charge the gateway again.');
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    /**
     * A parcel that has shipped has shipped. A gateway that refuses the capture leaves the payment
     * where an operator can retry it, rather than the shipment being undone.
     */
    public function testARefusedCaptureLeavesTheShipmentShippedAndThePaymentAuthorised(): void
    {
        $payment = $this->authorisedPayment();
        $this->gateway->willFail(NmiGatewayException::fromHttpStatus(400));

        $shipment = $this->shipmentFor($payment);
        $this->ship($shipment);

        self::assertSame(ShipmentInterface::STATE_SHIPPED, $shipment->getState());
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
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
        $recorder->record($payment, $this->authorisation(), NmiTransactionInterface::TYPE_AUTH);

        $this->manager->flush();

        return $payment;
    }

    private function shipmentFor(PaymentInterface $payment): ShipmentInterface
    {
        $order = $payment->getOrder();

        // Sylius resolves the order's own shipping state when a shipment ships, and that
        // transition starts at `ready` — which is where checkout leaves an order.
        $order?->setShippingState(OrderShippingStates::STATE_READY);

        $shipment = new Shipment();
        $shipment->setMethod($this->shippingMethod());
        $shipment->setOrder($order);
        $shipment->setState(ShipmentInterface::STATE_READY);
        $order?->addShipment($shipment);

        $this->manager->persist($shipment);
        $this->manager->flush();

        return $shipment;
    }

    /** A shipment cannot exist without a method, and a method cannot exist without a zone. */
    private function shippingMethod(): ShippingMethodInterface
    {
        $zone = new Zone();
        $zone->setCode('zone_' . bin2hex(random_bytes(4)));
        $zone->setName('Anywhere');
        $zone->setType(Zone::TYPE_COUNTRY);
        $this->manager->persist($zone);

        $method = new ShippingMethod();
        $method->setCode('shipping_' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Parcel');
        $method->setZone($zone);
        $method->setCalculator('flat_rate');
        $method->setConfiguration([]);
        $this->manager->persist($method);

        return $method;
    }

    private function ship(ShipmentInterface $shipment): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        $stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);

        $this->manager->flush();
    }

    private function authorisation(): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => self::AUTHORISATION,
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
