<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Lifecycle\AuthorizesFirst;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * An authorisation is voided when its order is cancelled through the platform's admin API — the
 * path that applies the order's transition directly, with none of the order screen's events.
 */
final class NmiAdminApiAuthorizedOrderTest extends WebTestCase
{
    use AuthorizesFirst;
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const PASSWORD = 'an-admin-password';

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

    /** *Cancelling the order through the admin API.* */
    public function testCancellingTheOrderVoidsItsAuthorization(): void
    {
        [$order, $payment] = $this->anAuthorizedOrder($this->authorization);
        $this->gateway->willApprove($this->authorization);

        $this->cancel($order);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $payment = $this->reloaded($payment);
        self::assertSame(PaymentInterface::STATE_CANCELLED, $payment->getState(), 'The order did not take its payment with it.');
        self::assertSame([], $this->gateway->voidedTransactionIds, 'The API waited for the gateway instead of queueing the void.');

        $this->runTheQueuedWork();

        $this->assertTheAuthorizationWasVoidedOnce($this->gateway, $payment, $this->authorization);
    }

    private function cancel(OrderInterface $order): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/v2/admin/orders/%s/cancel', (string) $order->getTokenValue()),
            server: [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_ACCEPT' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->anAdministratorsToken(),
            ],
            content: '{}',
        );
    }

    private function anAdministratorsToken(): string
    {
        $email = sprintf('admin+%s@example.com', bin2hex(random_bytes(4)));

        /** @var AdminUserInterface $admin */
        $admin = self::getContainer()->get('sylius.factory.admin_user')->createNew();
        $admin->setEmail($email);
        $admin->setUsername($email);
        $admin->setPlainPassword(self::PASSWORD);
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $admin->addRole('ROLE_API_ACCESS');
        $this->manager->persist($admin);
        $this->manager->flush();

        $this->client->request(
            'POST',
            '/api/v2/admin/administrators/token',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? null;
        self::assertIsString($token, 'No token: ' . (string) $this->client->getResponse()->getContent());

        return $token;
    }

    private function reloaded(PaymentInterface $payment): PaymentInterface
    {
        $id = $payment->getId();
        $this->manager->clear();
        $payment = $this->manager->find(Payment::class, $id);
        self::assertInstanceOf(PaymentInterface::class, $payment);

        return $payment;
    }

    private function queue(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.main');

        return $transport;
    }
}
