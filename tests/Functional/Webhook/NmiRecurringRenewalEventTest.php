<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Double\RecordingLogger;

/**
 * *A refund reaches the renewal.* A renewal is charged for a payment of its own, so what the gateway
 * later says about that charge — a refund performed in its portal — has to land on that payment, not
 * on the one that opened the credential.
 */
final class NmiRecurringRenewalEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    /** The opening payment's own transaction, which the credential cites. A fresh one every run. */
    private string $sale;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_evt_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);
        self::getContainer()->set('logger', new RecordingLogger());
    }

    protected function tearDown(): void
    {
        foreach (['jpm_martin_sylius_nmi_gateway_notice', 'jpm_martin_sylius_nmi_received_event'] as $table) {
            $this->manager->getConnection()->executeStatement(
                sprintf('DELETE FROM %s WHERE payment_method_code = :code', $table),
                ['code' => $this->code],
            );
        }

        parent::tearDown();
    }

    public function testARefundOfARenewalsChargeResolvesToTheRenewalsPayment(): void
    {
        $opening = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED);
        $credential = $this->aCredentialOpenedBy($opening);
        $renewal = $this->aRenewalOn($opening);
        $renewalCharge = (string) random_int(10_000_000_000, 99_999_999_999);
        $this->gateway->willApprove($renewalCharge, '24.99');
        $outcome = $this->charger()->charge($renewal, $credential);
        self::assertTrue($outcome->isApproved(), 'The renewal was never charged, so the test proves nothing.');

        $this->deliver('transaction.refund.success', 'ref-renewal', transactionId: $renewalCharge);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_REFUNDED, $this->stateOf($renewal));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->stateOf($opening), 'The refund landed on the payment that opened the credential.');
    }

    private function aCredentialOpenedBy(PaymentInterface $opening): NmiRecurringCredentialInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);
        $opening->getOrder()?->setCustomer($customer);

        $method = $opening->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($opening);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId($this->sale);
        $this->manager->persist($credential);
        $this->manager->flush();

        return $credential;
    }

    /** A payment the store created for a renewal: another order of the same customer, same method, new. */
    private function aRenewalOn(PaymentInterface $opening): PaymentInterface
    {
        $openingOrder = $opening->getOrder();
        self::assertInstanceOf(OrderInterface::class, $openingOrder);

        $order = new Order();
        $order->setChannel($openingOrder->getChannel());
        $order->setCustomer($openingOrder->getCustomer());
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setNumber('R' . random_int(100000000, 999999999));
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $this->manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setMethod($opening->getMethod());
        $payment->setCurrencyCode('USD');
        $payment->setAmount(2499);
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);
        $this->manager->persist($payment);
        $this->manager->flush();

        return $payment;
    }

    private function charger(): NmiRecurringChargerInterface
    {
        /** @var NmiRecurringChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.charger');

        return $charger;
    }
}
