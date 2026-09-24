<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\RefuseRecurringChargeHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Recurring\NmiRecurringChargerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiCardVerifier;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *The shop API cannot trigger a recurring charge.*
 *
 * Whoever holds an order's token can create a payment request for it and name the action. So every
 * action is tried here against a renewal's payment whose customer holds a recurring credential on the
 * same method — the recurring charge's own action included — and none may reach the gateway.
 */
final class NmiRecurringChargeThroughApiTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const JSON = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];

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
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.card_verifier', new FakeNmiCardVerifier());

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

    /**
     * The charge's own action, named by a client, is refused by whichever layer meets it first: the
     * platform's list of actions a shop client may name, from Sylius 2.2.9, or else this plugin's
     * provider for the action, which fails the request and says why.
     */
    public function testTheChargesOwnActionIsRefused(): void
    {
        [$order, $payment, $credential] = $this->aRenewalWhoseCustomerHoldsACredential();

        $created = $this->createPaymentRequest($order, $payment, NmiRecurringChargerInterface::ACTION);

        $response = $this->client->getResponse();
        if (422 === $response->getStatusCode()) {
            self::assertStringContainsString(NmiRecurringChargerInterface::ACTION, (string) $response->getContent(), 'Refused, but not for naming this action.');
        } else {
            self::assertSame(PaymentRequestInterface::STATE_FAILED, $created['state'] ?? null, sprintf('Neither refusal: HTTP %d.', $response->getStatusCode()));
            self::assertSame(RefuseRecurringChargeHandler::NOT_HERE, $created['responseData']['message_key'] ?? null);
        }
        $this->assertNothingWasCharged($payment, $credential);
    }

    /**
     * This plugin's own layer, whatever the platform lets a shop client name: a request for the
     * charge's action — carrying a credential's id, as the charger's own would — announced the way the
     * platform announces every request, fails and charges nothing.
     */
    public function testARequestForTheChargesActionAnnouncedLikeAnyOtherIsRefused(): void
    {
        [, $payment, $credential] = $this->aRenewalWhoseCustomerHoldsACredential();
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction(NmiRecurringChargerInterface::ACTION);
        $paymentRequest->setPayload(['credential' => $credential->getId()]);
        $this->manager->persist($paymentRequest);
        $this->manager->flush();

        /** @var PaymentRequestAnnouncerInterface $announcer */
        $announcer = self::getContainer()->get('sylius.announcer.payment_request');
        $announcer->dispatchPaymentRequestCommand($paymentRequest);

        $this->manager->refresh($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(RefuseRecurringChargeHandler::NOT_HERE, $paymentRequest->getResponseData()['message_key'] ?? null);
        $this->assertNothingWasCharged($payment, $credential);
    }

    /** @param non-empty-string $action */
    #[DataProvider('everyOtherAction')]
    public function testNoOtherActionChargesTheCredential(string $action): void
    {
        [$order, $payment, $credential] = $this->aRenewalWhoseCustomerHoldsACredential();

        $this->createPaymentRequest($order, $payment, $action);

        $this->assertNothingWasCharged($payment, $credential);
    }

    /** @return iterable<string, array{string}> */
    public static function everyOtherAction(): iterable
    {
        foreach ([
            PaymentRequestInterface::ACTION_CAPTURE,
            PaymentRequestInterface::ACTION_AUTHORIZE,
            PaymentRequestInterface::ACTION_STATUS,
            PaymentRequestInterface::ACTION_SYNC,
            PaymentRequestInterface::ACTION_NOTIFY,
        ] as $action) {
            yield $action => [$action];
        }
    }

    private function assertNothingWasCharged(PaymentInterface $payment, NmiRecurringCredentialInterface $credential): void
    {
        self::assertSame([], $this->gateway->operations, 'The gateway was asked to take money.');

        $payment = $this->manager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertFalse($credential->isReleased(), 'The credential was let go.');
    }

    /** @return array{OrderInterface, PaymentInterface, NmiRecurringCredentialInterface} */
    private function aRenewalWhoseCustomerHoldsACredential(): array
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('renewals+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        $first = $this->newPaymentRequest(customer: $customer)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $first);
        $method = $first->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_recurring_credential')->createNew();
        $credential->setInitialPayment($first);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('1736036779');
        $credential->setInitialTransactionId('12592792816');
        $this->manager->persist($credential);

        $paymentRequest = $this->newPaymentRequest(customer: $customer, paymentMethod: $method);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $this->manager->remove($paymentRequest);
        $order->setTokenValue('nmi_recurring_api_' . bin2hex(random_bytes(4)));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        return [$order, $payment, $credential];
    }

    /** @return array<string, mixed> */
    private function createPaymentRequest(OrderInterface $order, PaymentInterface $payment, string $action): array
    {
        $this->client->request(
            'POST',
            sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue()),
            server: self::JSON,
            content: json_encode([
                'paymentId' => $payment->getId(),
                'paymentMethodCode' => $payment->getMethod()?->getCode(),
                'action' => $action,
            ], \JSON_THROW_ON_ERROR),
        );

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
