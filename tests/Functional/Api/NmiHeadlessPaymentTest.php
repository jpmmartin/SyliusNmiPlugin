<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * The same payment, driven entirely through the platform's own shop API.
 *
 * The point of this file is what it does *not* contain: no endpoint of this plugin appears in any
 * URL below. If a headless client ever needed one, the design would have drifted away from the
 * platform's payment-request model, and that would be worth stopping for.
 */
final class NmiHeadlessPaymentTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

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

        // The client is replaced, not the HTTP layer: what is under test here is the API, and a
        // decline has to be one line of setup rather than a fixture at the gateway.
        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    /** One request in, everything the client needs to tokenise a card out. */
    public function testCreatingThePaymentRequestReturnsWhatIsNeededToTokenise(): void
    {
        $created = $this->createPaymentRequest();

        self::assertResponseIsSuccessful();
        self::assertSame(self::TOKENIZATION_KEY, $created['responseData']['tokenization_key'] ?? null);
        self::assertSame(self::AMOUNT, $created['responseData']['amount'] ?? null);
        self::assertSame('USD', $created['responseData']['currency_code'] ?? null);
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $created['state'] ?? null);

        self::assertStringNotContainsString(
            self::SECURITY_KEY,
            (string) $this->client->getResponse()->getContent(),
            'The private key must not reach an API client any more than it reaches a browser.',
        );
    }

    /** And the token back in the other direction, reaching the state the browser flow reaches. */
    public function testReturningTheTokenCompletesThePayment(): void
    {
        $this->gateway->willApprove('12513506464');

        $created = $this->createPaymentRequest();
        $paymentRequest = $this->sendPayload($created['hash'], [
            'payment_token' => '00000000-000000-000000-000000000000',
            'cavv' => 'AAABBBBBBBBBBBBBBBBBBBBBBBBB',
            'eci' => '05',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest['state'] ?? null);
        self::assertSame('12513506464', $paymentRequest['responseData']['transaction_id'] ?? null);

        // The authentication values a headless client collected travel with the charge, exactly as
        // they do from the browser. A client that does its own 3-D Secure is not a second-class
        // path: the same payload keys reach the same place.
        self::assertSame(
            ['cavv' => 'AAABBBBBBBBBBBBBBBBBBBBBBBBB', 'eci' => '05'],
            array_intersect_key(
                (array) $this->gateway->lastCharge?->threeDSecure?->toArray(),
                ['cavv' => null, 'eci' => null],
            ),
        );
    }

    /** A decline is readable through the API, and leaves the order payable. */
    public function testADeclinedHeadlessPaymentIsReadableAndLeavesTheOrderPayable(): void
    {
        $this->gateway->willFail(new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => '12513493102',
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'failed',
            'response' => '2',
            'response_text' => 'DECLINE',
            'response_code' => '200',
        ], \JSON_THROW_ON_ERROR))));

        $created = $this->createPaymentRequest();
        $this->sendPayload($created['hash'], ['payment_token' => '00000000-000000-000000-000000000000']);

        $read = $this->readPaymentRequest($created['hash']);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $read['state'] ?? null);
        self::assertSame('jpm_martin_sylius_nmi.payment.declined', $read['responseData']['message_key'] ?? null);
        self::assertSame('DECLINE', $read['responseData']['detail'] ?? null);

        $order = $this->orderFor($created['hash']);
        self::assertSame('awaiting_payment', $order->getPaymentState(), 'The order must still be payable.');
    }

    /**
     * The requirement the other three only imply: a headless client uses the platform's published
     * operations and nothing else. Asserted from the routes the platform actually matched, because
     * a URL written in a test proves only what the test author typed.
     */
    public function testNoEndpointOfThisPluginIsInvolved(): void
    {
        $this->gateway->willApprove('12513506465');

        $created = $this->createPaymentRequest();
        $routes = [$this->matchedRoute()];

        $this->sendPayload($created['hash'], ['payment_token' => '00000000-000000-000000-000000000000']);
        $routes[] = $this->matchedRoute();

        $this->readPaymentRequest($created['hash']);
        $routes[] = $this->matchedRoute();

        self::assertSame([
            'sylius_api_shop_payment_request_post',
            'sylius_api_shop_payment_request_put',
            'sylius_api_shop_payment_request_get',
        ], $routes);

        foreach ($routes as $route) {
            self::assertStringStartsNotWith('jpm_martin_sylius_nmi', $route, 'The plugin must add nothing a client has to call.');
        }
    }

    private function matchedRoute(): string
    {
        return (string) $this->client->getRequest()->attributes->get('_route');
    }

    /**
     * @return array<string, mixed>
     */
    private function createPaymentRequest(): array
    {
        $paymentRequest = $this->newPaymentRequest();
        $payment = $paymentRequest->getPayment();
        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        // The fixture built a payment request so the store is complete; the API makes its own.
        $this->manager->remove($paymentRequest);

        // A headless client addresses the order by its token, which is how the shop API lets a
        // guest act on their own order without a session.
        $order->setTokenValue('nmi_headless_' . bin2hex(random_bytes(4)));
        // The platform refuses a payment request for an order that has not been placed, which is
        // the state a headless client's order is in by the time it pays.
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        $this->client->request(
            'POST',
            sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue()),
            server: self::JSON,
            content: json_encode([
                'paymentId' => $payment->getId(),
                'paymentMethodCode' => $payment->getMethod()?->getCode(),
            ], \JSON_THROW_ON_ERROR),
        );

        return $this->decoded();
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array<string, mixed>
     */
    private function sendPayload(string $hash, array $payload): array
    {
        $this->client->request(
            'PUT',
            sprintf('/api/v2/shop/payment-requests/%s', $hash),
            server: self::JSON,
            content: json_encode(['payload' => $payload], \JSON_THROW_ON_ERROR),
        );

        return $this->decoded();
    }

    /** @return array<string, mixed> */
    private function readPaymentRequest(string $hash): array
    {
        $this->client->request('GET', sprintf('/api/v2/shop/payment-requests/%s', $hash), server: self::JSON);

        return $this->decoded();
    }

    private function orderFor(string $hash): OrderInterface
    {
        /** @var PaymentRequestInterface $paymentRequest */
        $paymentRequest = self::getContainer()->get('sylius.repository.payment_request')->find($hash);
        $this->manager->refresh($paymentRequest->getPayment()->getOrder());

        /** @var OrderInterface $order */
        $order = $paymentRequest->getPayment()->getOrder();

        return $order;
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true);

        self::assertIsArray($decoded, sprintf('Expected JSON, got HTTP %d: %s', $this->client->getResponse()->getStatusCode(), substr($content, 0, 400)));

        return $decoded;
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
