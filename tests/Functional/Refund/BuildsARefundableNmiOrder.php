<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Refund;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\RefundPlugin\Command\RefundUnits;
use Sylius\RefundPlugin\Model\ShipmentRefund;
use Sylius\RefundPlugin\Provider\SupportedRefundPaymentMethodsProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * An order the refund plugin can refund: paid with an NMI method, numbered, worth its shipping
 * fee, with the sale on record. The shipping fee is the one refundable unit, because a shipment
 * needs no product behind it and the refund plugin refunds a shipment by its shipping adjustment.
 *
 * Every test here is skipped in the configuration that does not have the refund plugin, which
 * is the point: the suite must pass in both.
 */
trait BuildsARefundableNmiOrder
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const SALE = '12513502276';

    private static function hasTheRefundPlugin(): bool
    {
        return class_exists(SupportedRefundPaymentMethodsProvider::class);
    }

    private function onlyWithTheRefundPlugin(): void
    {
        if (!self::hasTheRefundPlugin()) {
            self::markTestSkipped('The optional refund plugin is not installed in this configuration.');
        }
    }

    /**
     * @return array{0: OrderInterface, 1: PaymentInterface, 2: int} the order, its completed payment, and the id of the shipping adjustment the refund plugin refunds by
     */
    private function paidNmiOrder(bool $settled = true, ?PaymentMethodInterface $paymentMethod = null): array
    {
        $manager = $this->paymentRequestManager();

        // The credit memo the refund plugin writes is emailed to the order's customer, so the
        // order has one.
        $customer = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))))->getCustomer();

        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED, customer: $customer, paymentMethod: $paymentMethod);
        /** @var PaymentInterface $payment */
        $payment = $paymentRequest->getPayment();
        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);
        // A placed order, not a cart: Sylius's repository does not find a cart by its number, and
        // the refund plugin looks every order up that way.
        $order->setState(OrderInterface::STATE_NEW);
        $order->setNumber('NMI' . random_int(100000000, 999999999));

        // Worth its shipping fee and nothing else. Sylius files a shipping adjustment on the
        // order with the shipment it belongs to, which is what makes the order's total the fee
        // and the shipment refundable.
        $shipment = new Shipment();
        $shipment->setMethod($this->aShippingMethod());
        $order->addShipment($shipment);
        $adjustment = new Adjustment();
        $adjustment->setType(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        $adjustment->setLabel('Courier');
        $adjustment->setAmount(self::AMOUNT);
        $shipment->addAdjustment($adjustment);
        $manager->persist($shipment);
        $manager->persist($adjustment);

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $sale = $recorder->record($payment, $this->approved(self::SALE), NmiTransactionInterface::TYPE_SALE);
        if ($settled) {
            $sale->setSettledAt(new \DateTimeImmutable('-1 day'));
        }

        $manager->flush();

        self::assertSame(self::AMOUNT, $order->getTotal(), 'The fixture must be worth its fee, or the refund plugin refuses it as empty.');

        return [$order, $payment, (int) $adjustment->getId()];
    }

    /** A shipment must have a method; this one is the least a shipping method can be. */
    private function aShippingMethod(): ShippingMethodInterface
    {
        $manager = $this->paymentRequestManager();

        $zone = new Zone();
        $zone->setCode('nmi_zone_' . bin2hex(random_bytes(4)));
        $zone->setName('Everywhere');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $manager->persist($zone);

        $method = new ShippingMethod();
        $method->setCode('nmi_courier_' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Courier');
        $method->setCalculator('flat_rate');
        $method->setConfiguration([]);
        $method->setZone($zone);
        $manager->persist($method);

        return $method;
    }

    /** A second, enabled method on the same channel — offline, or another NMI account. */
    private function anotherMethodOn(OrderInterface $order, string $factoryName): PaymentMethodInterface
    {
        $manager = $this->paymentRequestManager();

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName($factoryName);
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setConfig('nmi' === $factoryName ? [
            'tokenization_key' => 'tok-public-4444',
            'security_key' => 'sec-private-8888',
            'api_base_url' => 'https://sandbox.nmi.com',
        ] : []);
        $gatewayConfig->setUsePayum(false);

        $method = new PaymentMethod();
        $method->setCode($factoryName . '_other_' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName(ucfirst($factoryName));
        $method->setGatewayConfig($gatewayConfig);
        $method->setEnabled(true);
        $channel = $order->getChannel();
        self::assertNotNull($channel);
        $method->addChannel($channel);
        $manager->persist($gatewayConfig);
        $manager->persist($method);
        $manager->flush();

        return $method;
    }

    /** What the operator does on the refund plugin's screen: refund part of the shipping fee through a method. */
    private function refundThroughTheRefundPlugin(OrderInterface $order, int $adjustmentId, int $amount, PaymentMethodInterface $method): void
    {
        // The bus the refund plugin's own screen dispatches on, with the validation and the
        // transaction that make a refusal undo the credit memo.
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('sylius.command_bus');
        $bus->dispatch(new RefundUnits((string) $order->getNumber(), [new ShipmentRefund($adjustmentId, $amount)], (int) $method->getId(), 'Test refund'));
    }

    private function approved(string $transactionId, string $amount = '12.99'): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
    }

    private function transactions(): NmiTransactionRepositoryInterface
    {
        /** @var NmiTransactionRepositoryInterface $repository */
        $repository = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        return $repository;
    }

    abstract protected function paymentRequestManager(): EntityManagerInterface;
}
