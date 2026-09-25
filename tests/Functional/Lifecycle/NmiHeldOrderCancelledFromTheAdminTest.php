<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Command\PurgeStoredCard;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *Released when the order is cancelled*, by an operator, from the order page: the platform's own
 * *Cancel* on the whole order, which cancels its payments through the state machine and never
 * fires the payment's order-screen events.
 */
final class NmiHeldOrderCancelledFromTheAdminTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', new FakeNmiClient());

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

    public function testCancellingTheOrderFromTheOrderPageReleasesTheCardOfItsHeldPayment(): void
    {
        [$order, $payment] = $this->aHeldOrder();
        $this->client->loginUser($this->anAdministrator(), 'admin');

        $page = $this->client->request('GET', sprintf('/admin/orders/%d', (int) $order->getId()));
        self::assertResponseIsSuccessful('The order page did not open.');
        $form = $page->filter(sprintf('form[action$="/orders/%d/cancel"]', (int) $order->getId()));
        self::assertCount(1, $form, 'The order page offers no Cancel for this order.');

        $this->client->request('POST', sprintf('/admin/orders/%d/cancel', (int) $order->getId()), [
            '_method' => 'PUT',
            '_csrf_token' => (string) $form->filter('input[name="_csrf_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects();
        $paymentId = $payment->getId();
        $this->manager->clear();
        $payment = $this->manager->find(Payment::class, $paymentId);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The order did not take its payment with it.');
        self::assertTrue(null === $this->heldCardOf($payment), 'The card is still held by a cancelled payment.');
        self::assertSame(['1256465022'], $this->queuedPurges(), 'Its removal from the gateway\'s vault was not queued once.');
    }

    /** @return array{OrderInterface, PaymentInterface} */
    private function aHeldOrder(): array
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('held+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $payment = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $this->aCardOnFileFor($payment);

        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setCheckoutCompletedAt(new \DateTime());
        $order->setNumber('H' . random_int(100000000, 999999999));
        $order->setTokenValue('nmi_held_' . bin2hex(random_bytes(4)));
        $this->manager->flush();

        return [$order, $payment];
    }

    private function anAdministrator(): AdminUserInterface
    {
        /** @var AdminUserInterface $admin */
        $admin = self::getContainer()->get('sylius.factory.admin_user')->createNew();
        $admin->setEmail(sprintf('admin+%s@example.com', bin2hex(random_bytes(4))));
        $admin->setUsername('admin-' . bin2hex(random_bytes(4)));
        $admin->setPlainPassword('not-checked');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $this->manager->persist($admin);
        $this->manager->flush();

        return $admin;
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
