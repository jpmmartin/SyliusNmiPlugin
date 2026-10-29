<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\RefuseRefundOrVoidHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *A shopper cannot give themselves their money back.*
 *
 * Whoever holds an order's token can create a payment request through the shop API, naming the
 * action. Sylius 2.2.9 and later refuse `refund` and `cancel` there before any request exists; before
 * 2.2.9 — or on a store that widens `sylius_api.shop_payment_request.allowed_actions` — the request is
 * created and announced. Until 1.3.3 the plugin then voided or refunded the sale at NMI and left the
 * payment completed: the order shipped and the shopper had the money back.
 */
final class NmiRefundAndVoidThroughShopApiTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private const JSON = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];

    private KernelBrowser $client;

    /** Fresh for every test: the record keeps one row per identifier and type, whoever wrote it. */
    private string $sale;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->sale = (string) random_int(12500000000, 12599999999);

        $this->gateway = new FakeNmiClient();
        $this->gateway->willApprove($this->sale);
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

    /**
     * Refused by whichever layer meets it first: the platform's list of shop actions where it has
     * one, and otherwise this plugin's provider for the action, which fails the request.
     *
     * @param non-empty-string $action
     */
    #[DataProvider('actionsThatGiveMoneyBack')]
    public function testNamingTheActionThroughTheShopApiMovesNoMoney(string $action): void
    {
        [$order, $payment] = $this->aPaidOrder();

        $created = $this->createPaymentRequest($order, $payment, $action);

        $response = $this->client->getResponse();
        if (422 !== $response->getStatusCode()) {
            self::assertSame(PaymentRequestInterface::STATE_FAILED, $created['state'] ?? null, sprintf('Neither refusal: HTTP %d.', $response->getStatusCode()));
            self::assertSame(RefuseRefundOrVoidHandler::NOT_HERE, $created['responseData']['message_key'] ?? null);
        }
        $this->assertNoMoneyMoved($payment);
    }

    /**
     * This plugin's own layer, reached whatever the platform lets a shop client name: a request
     * announced the way the platform announces every request fails, and NMI is asked nothing.
     *
     * @param non-empty-string $action
     */
    #[DataProvider('actionsThatGiveMoneyBack')]
    public function testARequestAnnouncedLikeAnyOtherMovesNoMoney(string $action): void
    {
        [, $payment] = $this->aPaidOrder();
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $paymentRequest = new PaymentRequest($payment, $method);
        $paymentRequest->setAction($action);
        $this->manager->persist($paymentRequest);
        $this->manager->flush();

        /** @var PaymentRequestAnnouncerInterface $announcer */
        $announcer = self::getContainer()->get('sylius.announcer.payment_request');
        $announcer->dispatchPaymentRequestCommand($paymentRequest);

        $this->manager->refresh($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(RefuseRefundOrVoidHandler::NOT_HERE, $paymentRequest->getResponseData()['message_key'] ?? null);
        $this->assertNoMoneyMoved($payment);
    }

    /** @return iterable<string, array{string}> */
    public static function actionsThatGiveMoneyBack(): iterable
    {
        yield 'refund' => [PaymentRequestInterface::ACTION_REFUND];
        yield 'void' => [PaymentRequestInterface::ACTION_CANCEL];
    }

    private function assertNoMoneyMoved(PaymentInterface $payment): void
    {
        self::assertSame([], $this->gateway->operations, 'NMI was asked to give the money back.');

        $payment = $this->manager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    /** @return array{OrderInterface, PaymentInterface} */
    private function aPaidOrder(): array
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setState(PaymentInterface::STATE_COMPLETED);

        /** @var NmiTransactionRecorderInterface $recorder */
        $recorder = self::getContainer()->get('test.jpm_martin_sylius_nmi.recorder.transaction');
        $recorder->record($payment, $this->approved($this->sale), NmiTransactionInterface::TYPE_SALE);

        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setTokenValue('nmi_shop_refund_' . bin2hex(random_bytes(4)));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);
        $this->manager->flush();

        return [$order, $payment];
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

    private function approved(string $transactionId): NmiResponse
    {
        return NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'amount' => '12.99',
            'currency' => 'USD',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
    }
}
