<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *A held payment is completed only by an approved charge*, through the platform's admin API — the
 * path that applies the transition directly, with none of the order screen's events.
 */
final class NmiAdminApiHeldPaymentTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const PASSWORD = 'an-admin-password';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

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

    /** *Completed through the admin API without a charge.* */
    public function testCompletingAPaymentThatHoldsACardOnFileIsRefused(): void
    {
        $payment = $this->aPaymentHoldingACardOnFile();

        $this->complete($payment);

        self::assertSame(422, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->gateway->operations, 'Something was sent to the gateway.');
        $payment = $this->reloaded($payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertTrue(null !== $this->heldCardOf($payment), 'The card is no longer held.');
        self::assertNotSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /** *A held payment kept on a recurring credential* is refused the same way. */
    public function testCompletingAHeldPaymentKeptOnARecurringCredentialIsRefused(): void
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);
        $payment = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $payment->setState(PaymentInterface::STATE_PROCESSING);
        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId('12592792407');
        $this->manager->persist($credential);
        $this->placed($payment);

        $this->complete($payment);

        self::assertSame(422, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->gateway->operations);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $this->reloaded($payment)->getState());
    }

    /** *A payment that holds nothing* completes through the admin API exactly as before. */
    public function testCompletingAnNmiPaymentThatHoldsNothingStillWorks(): void
    {
        $payment = $this->newPaymentRequest()->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_PROCESSING);
        $this->placed($payment);

        $this->complete($payment);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reloaded($payment)->getState());
    }

    private function complete(PaymentInterface $payment): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/v2/admin/payments/%d/complete', (int) $payment->getId()),
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

    private function aPaymentHoldingACardOnFile(): PaymentInterface
    {
        $paymentRequest = $this->newPaymentRequest();
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $this->aCardOnFileFor($payment);
        $this->placed($payment);

        return $payment;
    }

    /** The API answers with the payment's order by its token, as it does for any placed order. */
    private function placed(PaymentInterface $payment): void
    {
        $payment->getOrder()?->setTokenValue('nmi_admin_api_' . bin2hex(random_bytes(4)));
        $payment->getOrder()?->setNumber('A' . random_int(100000000, 999999999));
        $this->manager->flush();
    }

    private function reloaded(PaymentInterface $payment): PaymentInterface
    {
        $id = $payment->getId();
        $this->manager->clear();
        $payment = $this->manager->find(Payment::class, $id);
        self::assertInstanceOf(PaymentInterface::class, $payment);

        return $payment;
    }
}
