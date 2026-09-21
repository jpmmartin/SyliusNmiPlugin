<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
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
 * *Headless deferred checkout*, through nothing but the operations the platform's shop API already
 * documents: a client creates the payment request, tokenises the card, and sends the token back.
 */
final class NmiHeadlessDeferredCheckoutTest extends WebTestCase
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

    /** *The client is told nothing will be charged.* */
    public function testTheClientIsToldTheCardWillBePutOnFileAndNotCharged(): void
    {
        [$created] = $this->createPaymentRequest();

        self::assertResponseIsSuccessful();
        self::assertTrue($created['responseData']['card_on_file'] ?? null);
        self::assertSame(self::TOKENIZATION_KEY, $created['responseData']['tokenization_key'] ?? null);
        self::assertSame(self::AMOUNT, $created['responseData']['amount'] ?? null);
    }

    /** *Returning the token puts the card on file* — the same state the browser flow reaches. */
    public function testReturningTheTokenPutsTheCardOnFile(): void
    {
        [$created, $payment] = $this->createPaymentRequest();

        $this->client->request(
            'PUT',
            sprintf('/api/v2/shop/payment-requests/%s', (string) $created['hash']),
            server: self::JSON,
            content: json_encode(['payload' => [
                'payment_token' => '00000000-000000-000000-000000000000',
                'cardholder_auth' => 'verified',
                'cavv' => 'Y2FyZGluYWxjb21tZXJjZWF1dGg=',
                'eci' => '05',
            ]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $sent = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($sent);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $sent['state'] ?? null);
        self::assertTrue($sent['responseData']['card_on_file'] ?? null);

        // Looked up again: the request cleared the entity manager, so the reference is detached.
        $payment = $this->manager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertNotNull($this->heldCardOf($payment));
        self::assertSame([], $this->gateway->operations, 'Something was charged or authorised.');
        self::assertSame('verified', $this->verifier->lastVerification?->threeDSecure?->status);
    }

    /** @return array{array<string, mixed>, PaymentInterface} */
    private function createPaymentRequest(): array
    {
        $paymentRequest = $this->newPaymentRequest();
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);

        /** @var OrderInterface $order */
        $order = $payment->getOrder();
        $this->manager->remove($paymentRequest);
        $order->setTokenValue('nmi_headless_later_' . bin2hex(random_bytes(4)));
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        $this->client->request(
            'POST',
            sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue()),
            server: self::JSON,
            content: json_encode(['paymentId' => $payment->getId(), 'paymentMethodCode' => $method->getCode()], \JSON_THROW_ON_ERROR),
        );

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, sprintf('Expected JSON, got HTTP %d.', $this->client->getResponse()->getStatusCode()));

        return [$decoded, $payment];
    }
}
