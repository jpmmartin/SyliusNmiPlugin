<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CardOnFile\NmiCardOnFileChargerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\RefuseCardOnFileChargeHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiCardVerifier;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * *The shop API cannot trigger a charge.*
 *
 * The platform's shop API takes whatever action a client names when it creates a payment request,
 * and whoever holds an order's token can create one. So every action is tried here against a
 * payment holding a card on file — the charge's own included — and none may reach the gateway with
 * that card.
 */
final class NmiCardOnFileChargeThroughApiTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const JSON = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    private FakeNmiCardVerifier $verifier;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);
        $this->verifier = new FakeNmiCardVerifier();
        self::getContainer()->set('jpm_martin_sylius_nmi.gateway.card_verifier', $this->verifier);

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

    /** The charge's own action, named by a client: the request fails and says why. */
    public function testTheChargesOwnActionIsRefused(): void
    {
        [$order, $payment] = $this->anOrderWhosePaymentHoldsACard();

        $created = $this->createPaymentRequest($order, $payment, NmiCardOnFileChargerInterface::ACTION);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $created['state'] ?? null);
        self::assertSame(RefuseCardOnFileChargeHandler::NOT_HERE, $created['responseData']['message_key'] ?? null);
        $this->assertNothingWasCharged($payment);
    }

    /** @param non-empty-string $action */
    #[DataProvider('everyOtherAction')]
    public function testNoOtherActionChargesTheCardOnFile(string $action): void
    {
        [$order, $payment] = $this->anOrderWhosePaymentHoldsACard();

        $this->createPaymentRequest($order, $payment, $action);

        $this->assertNothingWasCharged($payment);
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

    private function assertNothingWasCharged(PaymentInterface $payment): void
    {
        self::assertSame([], $this->gateway->operations, 'The gateway was asked to take money.');

        $payment = $this->manager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertNotNull($this->heldCardOf($payment), 'The card on file is gone.');
    }

    /** @return array{OrderInterface, PaymentInterface} */
    private function anOrderWhosePaymentHoldsACard(): array
    {
        $paymentRequest = $this->newPaymentRequest();
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);
        $this->aCardOnFileFor($payment);

        $order = $payment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $this->manager->remove($paymentRequest);
        $order->setTokenValue('nmi_cof_api_' . bin2hex(random_bytes(4)));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
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
}
