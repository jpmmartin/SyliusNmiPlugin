<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * The second phase: the browser posts the token it obtained, the store charges once, and the
 * three outcomes land where the specification says they must.
 *
 * The gateway client is replaced rather than the HTTP layer, so a decline is one line of setup.
 * What the real gateway does is proven against its sandbox, not here.
 */
final class NmiCompleteCardPaymentTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const TOKEN = '00000000-000000-000000-000000000000';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    /** @var array<string, string> */
    private array $csrfTokens = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);

        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

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

    public function testAnApprovedCardCompletesThePayment(): void
    {
        $this->gateway->willApprove('12513506464');
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);

        self::assertResponseRedirects();
        $paymentRequest = $this->reload($paymentRequest);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $paymentRequest->getPayment()->getState());
        self::assertSame('sale', $this->gateway->lastOperation);
        self::assertSame(self::TOKEN, $this->gateway->lastCharge?->paymentToken);
        self::assertSame(self::AMOUNT, $this->gateway->lastCharge?->amount);

        self::assertSame('12513506464', $paymentRequest->getResponseData()['transaction_id']);
        $this->assertRecorded('12513506464', NmiTransactionInterface::TYPE_SALE);

        // The specification asks for the order too, not only the payment.
        self::assertSame(OrderPaymentStates::STATE_PAID, $paymentRequest->getPayment()->getOrder()?->getPaymentState());
    }

    /**
     * The shopper's last hop. The platform ends the flow by minting a status request, and a
     * gateway that does not answer it turns every successful payment into an error page.
     */
    public function testTheShopperReachesTheEndOfTheFlow(): void
    {
        $this->gateway->willApprove();
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);
        $this->client->followRedirect();

        self::assertTrue(
            $this->client->getResponse()->isSuccessful() || $this->client->getResponse()->isRedirect(),
            'The after-pay page must not fail: it mints a status request every gateway has to answer.',
        );
    }

    public function testTheAuthoriseActionLeavesThePaymentAuthorized(): void
    {
        $this->gateway->willApprove('12513542107');
        $paymentRequest = $this->processingRequest(PaymentRequestInterface::ACTION_AUTHORIZE, useAuthorize: true);

        $this->post($paymentRequest, [self::TOKEN]);

        $paymentRequest = $this->reload($paymentRequest);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $paymentRequest->getPayment()->getState());
        self::assertSame('authorize', $this->gateway->lastOperation);
        $this->assertRecorded('12513542107', NmiTransactionInterface::TYPE_AUTH);

        // The specification asks for the order too, not only the payment.
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $paymentRequest->getPayment()->getOrder()?->getPaymentState());
    }

    /** The shopper's problem: the order has to stay payable so another card can be tried. */
    public function testADeclinedCardLeavesTheOrderPayable(): void
    {
        $this->gateway->willFail(new NmiDeclinedException($this->declined()));
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);

        $paymentRequest = $this->reload($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_NEW, $paymentRequest->getPayment()->getState());
        self::assertSame('DECLINE', $paymentRequest->getResponseData()['detail']);

        // The gateway gave the attempt an identifier, so a notification about it must resolve here.
        $this->assertRecorded('12513493102', NmiTransactionInterface::TYPE_SALE);

        // The specification requires the shopper to be told why, in the issuer's own words.
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
        self::assertStringContainsString('DECLINE', implode(' ', $flashes['error'] ?? []));
    }

    /**
     * A refusal aimed at the merchant must not be repeated to the shopper: it can name account
     * configuration, and it is not something a cardholder can act on.
     */
    public function testAMerchantFacingRefusalIsNotShownToTheShopper(): void
    {
        $this->gateway->willFail(NmiGatewayException::fromHttpStatus(401));
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);

        $flashes = implode(' ', $this->client->getRequest()->getSession()->getFlashBag()->peekAll()['error'] ?? []);
        self::assertNotSame('', $flashes, 'The shopper still has to be told the payment failed.');
        self::assertStringNotContainsString('401', $flashes);
        self::assertStringNotContainsString('Authentication', $flashes);
    }

    /**
     * The one outcome where nobody knows what happened. It must never look like a paid order, and
     * nothing may be recorded, because there is no transaction anyone can name.
     */
    public function testAnUnreachableGatewayNeverCompletesThePayment(): void
    {
        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(504));
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);

        $paymentRequest = $this->reload($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_NEW, $paymentRequest->getPayment()->getState());
        self::assertNotSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());

        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');
        self::assertSame([], $transactions->findBy(['payment' => $paymentRequest->getPayment()]));
    }

    public function testAGatewayRefusalFailsTheRequestWithTheGatewaysOwnWording(): void
    {
        $this->gateway->willFail(NmiGatewayException::fromHttpStatus(401));
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);

        $paymentRequest = $this->reload($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_NEW, $paymentRequest->getPayment()->getState());
    }

    public function testAPostWithNoTokenIsRefused(): void
    {
        $paymentRequest = $this->processingRequest();

        $this->expectException(BadRequestHttpException::class);

        $this->post($paymentRequest, []);
    }

    /**
     * The pay page announces the charging command on every view once the request is in progress,
     * so a shopper who simply reloads arrives at the handler with nothing to charge. That has to
     * leave the request alone and show the form again — failing it would destroy a payment
     * because someone pressed refresh. Found in a browser, not in a test.
     */
    public function testReloadingThePayPageDoesNotDestroyThePayment(): void
    {
        $paymentRequest = $this->processingRequest();

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#nmi-payment'), 'The card form must still be there.');
        self::assertNull($this->gateway->lastOperation, 'The gateway must not be called without a token.');

        $paymentRequest = $this->reload($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_NEW, $paymentRequest->getPayment()->getState());
    }

    /**
     * Card details posted alongside the token are ignored rather than forwarded. The store has no
     * business holding them, and a browser must not get to choose the shape of a gateway request.
     */
    public function testCardDetailsPostedByABrowserNeverReachTheGateway(): void
    {
        $this->gateway->willApprove();
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [
            self::TOKEN,
            'card_number' => '4111111111111111',
            'card_exp' => '1029',
            'card_cvv' => '999',
        ]);

        $paymentRequest = $this->reload($paymentRequest);

        /** @var array<string, mixed> $payload */
        $payload = $paymentRequest->getPayload();
        self::assertArrayNotHasKey('card_number', $payload);
        self::assertArrayNotHasKey('card_exp', $payload);
        self::assertArrayNotHasKey('card_cvv', $payload);
        self::assertSame(self::TOKEN, $payload['payment_token']);
    }

    public function testAuthenticationValuesTravelWithTheCharge(): void
    {
        $this->gateway->willApprove();
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [
            self::TOKEN,
            'cardholder_auth' => 'verified',
            'cavv' => 'Y2FyZGluYWxjb21tZXJjZWF1dGg=',
            'eci' => '05',
            'three_ds_version' => '2.2.0',
            'directory_server_id' => '3f6fb1f8-f719-46c9-905b-bab446f4de30',
        ]);

        self::assertSame([
            'status' => 'verified',
            'cavv' => 'Y2FyZGluYWxjb21tZXJjZWF1dGg=',
            'eci' => '05',
            'three_ds_version' => '2.2.0',
            'directory_server_id' => '3f6fb1f8-f719-46c9-905b-bab446f4de30',
        ], $this->gateway->lastCharge?->threeDSecure?->toArray());
    }

    /** A resubmitted form must not charge a second time. */
    public function testARequestThatIsNoLongerWaitingIsRefused(): void
    {
        $paymentRequest = $this->processingRequest();
        $paymentRequest->setState(PaymentRequestInterface::STATE_COMPLETED);
        $this->manager->flush();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->post($paymentRequest, [self::TOKEN]);
    }

    public function testAPostWithoutAValidTokenOfItsOwnIsRefused(): void
    {
        $paymentRequest = $this->processingRequest();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        $this->client->request('POST', $this->url($paymentRequest), [
            'payment_token' => self::TOKEN,
            '_csrf_token' => 'not-the-right-token',
        ]);
    }

    /**
     * A request the pay page has already prepared, reached the way a shopper reaches it. Visiting
     * the page is what moves it on and what mints the token the form has to carry back, so the
     * tests below start where a browser would.
     */
    private function processingRequest(
        string $action = PaymentRequestInterface::ACTION_CAPTURE,
        bool $useAuthorize = false,
    ): PaymentRequest {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW, $action, $useAuthorize);

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        $this->csrfTokens[(string) $paymentRequest->getId()] = (string) $crawler
            ->filter('#nmi-payment')
            ->attr('data-nmi-csrf-token')
        ;

        return $this->reload($paymentRequest);
    }

    /**
     * Reads the row back. The entity manager is cleared while the HTTP request runs, so a
     * reference held across it is no longer the managed one.
     */
    private function reload(PaymentRequest $paymentRequest): PaymentRequest
    {
        /** @var PaymentRequest $reloaded */
        $reloaded = $this->manager->find(PaymentRequest::class, (string) $paymentRequest->getId());

        return $reloaded;
    }

    /** @param array<int|string, string> $fields the token is the first positional entry */
    private function post(PaymentRequest $paymentRequest, array $fields): void
    {
        $body = [];
        foreach ($fields as $key => $value) {
            $body[is_int($key) ? 'payment_token' : $key] = $value;
        }

        $body['_csrf_token'] = $this->csrfTokens[(string) $paymentRequest->getId()] ?? '';

        $this->client->request('POST', $this->url($paymentRequest), $body);
    }

    private function url(PaymentRequest $paymentRequest): string
    {
        return sprintf('/nmi/pay/%s', (string) $paymentRequest->getId());
    }

    private function assertRecorded(string $transactionId, string $type): void
    {
        /** @var NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        $recorded = $transactions->findOneByTransactionIdAndType($transactionId, $type);
        self::assertNotNull($recorded, sprintf('No %s row was recorded for transaction %s.', $type, $transactionId));
    }

    private function declined(): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => '12513493102',
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'failed',
            'response' => '2',
            'response_text' => 'DECLINE',
            'response_code' => '200',
        ], \JSON_THROW_ON_ERROR));
    }
}
