<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * An authorisation is voided when an operator cancels its whole order from the order page: the
 * platform's own *Cancel*, which cancels the order's payments through the state machine and never
 * fires the payment's order-screen events.
 */
final class NmiAuthorizedOrderCancelledFromTheAdminTest extends WebTestCase
{
    use AuthorizesFirst;
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private KernelBrowser $client;

    /** Fresh for every test: the record keeps one row per identifier and type, whoever wrote it. */
    private string $authorization;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->authorization = (string) random_int(12500000000, 12599999999);
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

    public function testCancellingTheOrderFromTheOrderPageVoidsItsAuthorization(): void
    {
        [$order, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);
        $this->client->loginUser($this->anAdministrator(), 'admin');

        $this->cancelFromTheOrderPage($order);

        self::assertResponseRedirects();
        $payment = $this->reloaded($payment);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The order did not take its payment with it.');
        self::assertSame(OrderInterface::STATE_CANCELLED, $payment->getOrder()?->getState());
        self::assertSame([], $this->gateway->voidedTransactionIds, 'The order page waited for the gateway instead of queueing the void.');

        $this->runTheQueuedWork();

        $this->assertTheAuthorizationWasVoidedOnce($this->gateway, $payment, $this->authorization);
    }

    /**
     * *Voided from the payment row*, which is unchanged: it voids before the cancellation and asks
     * nothing more of the queue.
     */
    public function testVoidingThePaymentFromItsRowSendsOneVoidAndQueuesNothing(): void
    {
        [$order, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);
        $this->client->loginUser($this->anAdministrator(), 'admin');

        $page = $this->client->request('GET', sprintf('/admin/orders/%d', (int) $order->getId()));
        self::assertResponseIsSuccessful('The order page did not open.');
        $form = $page->filter(sprintf('form[action$="/orders/%d/payments/%d/void"]', (int) $order->getId(), (int) $payment->getId()));
        self::assertCount(1, $form, 'The payment row offers no Void.');
        $this->client->request('POST', (string) $form->attr('action'), [
            '_method' => 'PUT',
            '_csrf_token' => (string) $form->filter('input[name="_csrf_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects();
        $payment = $this->reloaded($payment);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState());
        self::assertSame([], $this->queue()->getSent(), 'The payment row\'s void queued more work.');
        $this->assertTheAuthorizationWasVoidedOnce($this->gateway, $payment, $this->authorization);
    }

    private function cancelFromTheOrderPage(OrderInterface $order): void
    {
        $page = $this->client->request('GET', sprintf('/admin/orders/%d', (int) $order->getId()));
        self::assertResponseIsSuccessful('The order page did not open.');
        $form = $page->filter(sprintf('form[action$="/orders/%d/cancel"]', (int) $order->getId()));
        self::assertCount(1, $form, 'The order page offers no Cancel for this order.');

        $this->client->request('POST', sprintf('/admin/orders/%d/cancel', (int) $order->getId()), [
            '_method' => 'PUT',
            '_csrf_token' => (string) $form->filter('input[name="_csrf_token"]')->attr('value'),
        ]);
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

    private function reloaded(PaymentInterface $payment): PaymentInterface
    {
        $id = $payment->getId();
        $this->manager->clear();
        $payment = $this->manager->find(Payment::class, $id);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertInstanceOf(Order::class, $payment->getOrder());

        return $payment;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }
}
