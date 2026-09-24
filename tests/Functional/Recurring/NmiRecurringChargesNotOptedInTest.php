<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Recurring;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClient;
use JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;
use Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway\Double\RecordingHttpClient;

/**
 * *No effect until a store opts in.* With the policy the plugin ships, each kind of checkout sends the
 * gateway the request it sent before recurring charges existed, read off the wire through the plugin's
 * real client, and no recurring credential is kept.
 *
 * The one change the client itself took is behind the flag a charge carries when it opens a stored
 * credential, and only the store's policy sets it; so what is held here is that nothing sets it.
 */
final class NmiRecurringChargesNotOptedInTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const TOKEN = '00000000-000000-000000-000000000000';

    private const JSON = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];

    /** Where the shopper's browser is, as every charge at checkout sends it. */
    private const ORDER_DETAILS = ['ip_address' => '127.0.0.1'];

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private RecordingHttpClient $network;

    /** @var array<string, string> */
    private array $csrfTokens = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $psr17 = new Psr17Factory();
        $this->network = new RecordingHttpClient($psr17);
        $client = new NmiClient($this->network, $psr17, $psr17, new NmiAmountFormatter());
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $client);
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.card_verifier', $client);

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

    public function testChargingNowSendsTheSaleItAlwaysSent(): void
    {
        $this->network->willAnswer(200, $this->approved());

        $this->checkOut($paymentRequest = $this->preparedRequest());

        self::assertSame('/api/v5/payments/sale', $this->lastPath());
        self::assertSame([
            'amount' => '109.51',
            'currency' => 'USD',
            'payment_details' => ['payment_token' => self::TOKEN],
            'order_details' => self::ORDER_DETAILS,
        ], $this->network->lastBodyJson());
        $this->assertNoCredentialWasKept($paymentRequest);
    }

    public function testAuthorisingFirstSendsTheAuthorisationItAlwaysSent(): void
    {
        $this->network->willAnswer(200, $this->approved());

        $this->checkOut($paymentRequest = $this->preparedRequest(useAuthorize: true));

        self::assertSame('/api/v5/payments/auth', $this->lastPath());
        self::assertSame([
            'amount' => '109.51',
            'currency' => 'USD',
            'payment_details' => ['payment_token' => self::TOKEN],
            'order_details' => self::ORDER_DETAILS,
        ], $this->network->lastBodyJson());
        $this->assertNoCredentialWasKept($paymentRequest);
    }

    public function testTakingPaymentLaterSendsTheVerificationItAlwaysSent(): void
    {
        $this->network->willAnswer(200, $this->approved(vaulted: true, amount: '0.00'));

        $this->checkOut($paymentRequest = $this->preparedRequest(takePaymentLater: true));

        self::assertSame('/api/v5/payments/validate', $this->lastPath());
        self::assertSame([
            'currency' => 'USD',
            'payment_details' => ['payment_token' => self::TOKEN],
            'customer_vault' => ['add_to_vault' => true],
            'cit_mit' => ['stored_credential_indicator' => 'stored', 'initiated_by' => 'customer'],
        ], $this->network->lastBodyJson());
        $this->assertNoCredentialWasKept($paymentRequest);
    }

    public function testSavingTheCardSendsTheSaleItAlwaysSent(): void
    {
        $this->network->willAnswer(200, $this->approved(vaulted: true));
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        $this->checkOut($paymentRequest = $this->preparedRequest(storeCards: true, customer: $customer), ['store_card' => '1']);

        self::assertSame('/api/v5/payments/sale', $this->lastPath());
        self::assertSame([
            'amount' => '109.51',
            'currency' => 'USD',
            'payment_details' => ['payment_token' => self::TOKEN],
            'order_details' => self::ORDER_DETAILS,
            'customer_vault' => ['add_to_vault' => true],
        ], $this->network->lastBodyJson());
        $this->assertNoCredentialWasKept($paymentRequest);
    }

    /**
     * *A shop API client cannot open recurring charges.* Whatever it adds to the payload — the
     * prepared data's own key, a declaration of its own — the store's policy said no, and nothing is
     * kept or declared.
     */
    public function testAShopApiClientClaimingRecurringChargesOpensNothing(): void
    {
        $this->network->willAnswer(200, $this->approved(vaulted: true));
        $fixture = $this->newPaymentRequest();
        $payment = $fixture->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $order = $payment->getOrder();
        self::assertNotNull($order);
        $this->manager->remove($fixture);
        $order->setTokenValue('nmi_not_opted_in_' . bin2hex(random_bytes(4)));
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        $this->client->request('POST', sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue()), server: self::JSON, content: json_encode([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => $payment->getMethod()?->getCode(),
        ], \JSON_THROW_ON_ERROR));
        $hash = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['hash'] ?? '');
        self::assertNotSame('', $hash, 'No payment request was created.');

        $this->client->request('PUT', sprintf('/api/v2/shop/payment-requests/%s', $hash), server: self::JSON, content: json_encode(['payload' => [
            'payment_token' => self::TOKEN,
            'recurring_charges' => true,
            'opens_recurring_charges' => '1',
            'cit_mit' => ['stored_credential_indicator' => 'stored', 'initiated_by' => 'customer'],
        ]], \JSON_THROW_ON_ERROR));

        self::assertSame('/api/v5/payments/sale', $this->lastPath());
        self::assertSame([
            'amount' => '109.51',
            'currency' => 'USD',
            'payment_details' => ['payment_token' => self::TOKEN],
        ], array_diff_key($this->network->lastBodyJson(), ['order_details' => true]), 'The client\'s claim reached the gateway.');
        /** @var PaymentRequest $paymentRequest */
        $paymentRequest = $this->manager->find(PaymentRequest::class, $hash);
        $this->assertNoCredentialWasKept($paymentRequest);
        self::assertArrayNotHasKey('recurring_charges', $paymentRequest->getResponseData());
    }

    /** Read for this checkout's payment and customer only: other tests leave credentials of their own. */
    private function assertNoCredentialWasKept(PaymentRequest $paymentRequest): void
    {
        // Read again: the request cleared what this test held.
        $payment = $this->manager->find(Payment::class, $paymentRequest->getPayment()->getId());
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertNotSame(PaymentInterface::STATE_NEW, $payment->getState(), 'The checkout never acted on the gateway\'s answer.');

        /** @var NmiRecurringCredentialRepositoryInterface $credentials */
        $credentials = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_recurring_credential');
        self::assertNull($credentials->findOpenedBy($payment));

        $customer = $payment->getOrder()?->getCustomer();
        if ($customer instanceof CustomerInterface) {
            self::assertSame([], $credentials->findUnreleasedOf($customer));
        }
    }

    private function preparedRequest(bool $storeCards = false, ?CustomerInterface $customer = null, bool $takePaymentLater = false, bool $useAuthorize = false): PaymentRequest
    {
        $paymentRequest = $this->newPaymentRequest(
            PaymentRequestInterface::STATE_NEW,
            $useAuthorize ? PaymentRequestInterface::ACTION_AUTHORIZE : PaymentRequestInterface::ACTION_CAPTURE,
            useAuthorize: $useAuthorize,
            storeCards: $storeCards,
            customer: $customer,
        );
        $method = $paymentRequest->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method, $takePaymentLater);

        $crawler = $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));
        $this->csrfTokens[(string) $paymentRequest->getId()] = (string) $crawler->filter('[data-nmi-payment]')->attr('data-nmi-csrf-token');

        return $paymentRequest;
    }

    /** @param array<string, string> $fields */
    private function checkOut(PaymentRequest $paymentRequest, array $fields = []): void
    {
        $fields['payment_token'] = self::TOKEN;
        $fields['_csrf_token'] = $this->csrfTokens[(string) $paymentRequest->getId()] ?? '';

        $this->client->request('POST', sprintf('/nmi/pay/%s', (string) $paymentRequest->getId()), $fields);
    }

    private function lastPath(): string
    {
        return (string) $this->network->lastRequest?->getUri()->getPath();
    }

    private function approved(bool $vaulted = false, string $amount = '109.51'): string
    {
        $body = [
            'object' => 'transaction', 'id' => (string) random_int(10_000_000_000, 99_999_999_999), 'type' => 'cc',
            'amount' => $amount, 'currency' => 'USD', 'status' => 'pendingsettlement',
            'response' => '1', 'response_text' => 'SUCCESS', 'response_code' => '100',
        ];
        if ($vaulted) {
            $body['customer_vault_id'] = '1736036779';
            $body['payment_details'] = ['card_number' => '411111******1111', 'card_exp' => '1029', 'card_type' => 'Visa', 'card_bin' => '411111'];
        }

        return (string) json_encode($body);
    }
}
