<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiChargeOutcome;
use JpmMartin\SyliusNmiPlugin\Command\NotifyCardholder;
use JpmMartin\SyliusNmiPlugin\CommandHandler\ChargeRecurringCredentialHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredential;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * *Card updates reach recurring credentials*, through the same signed summaries saved cards and cards
 * on file are updated by: a renewed card keeps the renewals going with its new expiry and digits, and a
 * closed account refuses the next renewal before the gateway is asked. Nobody is emailed: the email is
 * about a card a shopper saved, and this one was kept for the store's renewals.
 */
final class NmiRecurringCredentialUpdaterEventTest extends WebTestCase
{
    use BuildsAnNmiWebhookDelivery;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private string $code;

    private string $sale;

    /** Fresh each run: this test commits, as the other updater tests do. */
    private string $vaultId;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->code = 'nmi_rc_acu_' . bin2hex(random_bytes(4));
        $this->sale = (string) random_int(10_000_000_000, 99_999_999_999);
        $this->vaultId = 'vault-rc-' . bin2hex(random_bytes(4));

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->queue()->reset();
    }

    protected function tearDown(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM jpm_martin_sylius_nmi_received_event WHERE payment_method_code = :code',
            ['code' => $this->code],
        );

        parent::tearDown();
    }

    /** *A closed card* — marked closed, the next renewal refused as closed, and nobody emailed. */
    public function testAClosedAccountMarksTheCredentialClosedAndRefusesTheNextRenewal(): void
    {
        $credential = $this->aCredential(emailCardholder: true);

        $this->deliverEvent('acu.summary.closedaccount', 'rc-closed', [
            'vault_count_updated_closed_account' => 1,
            'vault_updates' => [$this->entry($this->vaultId)],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $updated = $this->refreshed($credential);
        self::assertSame(NmiRecurringCredentialInterface::STATUS_CLOSED, $updated->getStatus());
        self::assertFalse($updated->isUsable());
        self::assertSame([], $this->queuedCardholderEmails());

        $outcome = $this->charger()->charge($this->aRenewalOf($updated), $updated);
        self::assertSame(NmiChargeOutcome::REFUSED, $outcome->status);
        self::assertSame(ChargeRecurringCredentialHandler::CLOSED, $outcome->messageKey);
        self::assertSame([], $this->gateway->operations, 'The gateway was contacted.');
    }

    /** *A renewed card* — with a new number, so the digits move with the expiry, and it can be charged. */
    public function testARenewalBringsTheExpiryAndTheNumberUpToDateAndTheCredentialCanBeCharged(): void
    {
        $credential = $this->aCredential(expiryYear: 2020);
        self::assertTrue($credential->isExpired(), 'It starts expired, which is the point of the scenario.');

        $this->deliverEvent('acu.summary.automaticallyupdated', 'rc-renewed', [
            'vault_updated_cards' => [$this->entry($this->vaultId, '400000******0077', '01/50')],
        ]);

        $updated = $this->refreshed($credential);
        self::assertSame('0077', $updated->getLastFour());
        self::assertSame(1, $updated->getExpiryMonth());
        self::assertSame(2050, $updated->getExpiryYear());
        self::assertFalse($updated->isExpired());
        self::assertTrue($updated->isUsable());

        $this->gateway->willApprove((string) random_int(10_000_000_000, 99_999_999_999), '24.99');
        self::assertTrue($this->charger()->charge($this->aRenewalOf($updated), $updated)->isApproved());
    }

    /** Only the expiry changed: the same array's twin, treated the same. */
    public function testAnExpiryOnlyRenewalBringsTheExpiryUpToDate(): void
    {
        $credential = $this->aCredential(expiryYear: 2020);

        $this->deliverEvent('acu.summary.automaticallyupdated', 'rc-expiry', [
            'vault_updated_expiration_dates' => [$this->entry($this->vaultId, '411111******1111', '03/49')],
        ]);

        $updated = $this->refreshed($credential);
        self::assertSame('1111', $updated->getLastFour());
        self::assertSame(3, $updated->getExpiryMonth());
        self::assertSame(2049, $updated->getExpiryYear());
    }

    /** A request to contact the cardholder changes nothing: the card can still be charged. */
    public function testAContactTheCardholderSummaryLeavesTheCredentialAsItWas(): void
    {
        $credential = $this->aCredential();

        $this->deliverEvent('acu.summary.contactcustomer', 'rc-contact', [
            'vault_updates' => [$this->entry($this->vaultId)],
        ]);

        $updated = $this->refreshed($credential);
        self::assertSame(NmiRecurringCredentialInterface::STATUS_ACTIVE, $updated->getStatus());
        self::assertTrue($updated->isUsable());
    }

    private function aCredential(int $expiryYear = 2031, bool $emailCardholder = false): NmiRecurringCredentialInterface
    {
        $payment = $this->aPaymentIn(PaymentInterface::STATE_COMPLETED, $emailCardholder);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $customer = new Customer();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);
        $payment->getOrder()?->setCustomer($customer);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId($this->vaultId);
        $credential->setInitialTransactionId($this->sale);
        $credential->setBrand('Visa');
        $credential->setLastFour('1111');
        $credential->setExpiryMonth(10);
        $credential->setExpiryYear($expiryYear);
        $this->manager->persist($credential);
        $this->manager->flush();

        return $credential;
    }

    /** A new payment of another order of the same customer, on the credential's method. */
    private function aRenewalOf(NmiRecurringCredentialInterface $credential): PaymentInterface
    {
        $opening = $credential->getInitialPayment()?->getOrder();
        self::assertInstanceOf(OrderInterface::class, $opening);

        $order = new Order();
        $order->setChannel($opening->getChannel());
        $order->setCustomer($credential->getCustomer());
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $this->manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setMethod($credential->getPaymentMethod());
        $payment->setCurrencyCode('USD');
        $payment->setAmount(2499);
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);
        $this->manager->persist($payment);
        $this->manager->flush();

        return $payment;
    }

    /** @return array<string, string> */
    private function entry(string $vaultId, string $number = '400000******0002', string $expiry = '11/70'): array
    {
        return ['customer_vault_id' => $vaultId, 'cc_number' => $number, 'cc_exp' => $expiry];
    }

    private function refreshed(NmiRecurringCredentialInterface $credential): NmiRecurringCredentialInterface
    {
        $this->manager->clear();

        /** @var NmiRecurringCredentialInterface $fresh */
        $fresh = $this->manager->find(NmiRecurringCredential::class, $credential->getId());

        return $fresh;
    }

    private function charger(): NmiRecurringChargerInterface
    {
        /** @var NmiRecurringChargerInterface $charger */
        $charger = self::getContainer()->get('test.jpm_martin_sylius_nmi.recurring.charger');

        return $charger;
    }

    /** @return list<NotifyCardholder> */
    private function queuedCardholderEmails(): array
    {
        $emails = [];
        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NotifyCardholder) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }
}
