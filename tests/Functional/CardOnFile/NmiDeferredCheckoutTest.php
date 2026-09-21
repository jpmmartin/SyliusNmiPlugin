<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\PutCardOnFileHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiCardVerifier;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * The checkout on a method that takes payment later, reached the way a shopper reaches it: the pay
 * page is opened, which prepares the request and mints the form's token, and the card is posted
 * to the plugin's own endpoint.
 */
final class NmiDeferredCheckoutTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;
    use TakesPaymentLater;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 10951;

    private const TOKEN = '00000000-000000-000000-000000000000';

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    private FakeNmiCardVerifier $verifier;

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
        $this->verifier = new FakeNmiCardVerifier();
        $container->set('jpm_martin_sylius_nmi.gateway.card_verifier', $this->verifier);

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

    /** *The client is told nothing will be charged* — on the page's own request, before any card. */
    public function testThePreparedRequestSaysTheCardWillBePutOnFile(): void
    {
        $paymentRequest = $this->processingRequest();

        $data = $paymentRequest->getResponseData();
        self::assertTrue($data['card_on_file'] ?? null);
        self::assertSame(self::TOKENIZATION_KEY, $data['tokenization_key'] ?? null);
        self::assertSame(self::AMOUNT, $data['amount'] ?? null);
    }

    /** *Authenticated for the amount that may be charged.* */
    public function testTheCardIsAuthenticatedForTheOrderTotal(): void
    {
        $paymentRequest = $this->processingRequest();

        self::assertSame('109.51', $paymentRequest->getResponseData()['amount_major'] ?? null);
    }

    /** The button does not say "Pay" where nothing is paid: it says what happens. */
    public function testTheButtonSavesTheCardRatherThanPaying(): void
    {
        $this->processingRequest();

        $button = (new \Symfony\Component\DomCrawler\Crawler((string) $this->client->getResponse()->getContent()))->filter('[data-nmi-pay-button]');
        self::assertCount(1, $button);
        self::assertSame('Save card for this order', trim($button->text()));
    }

    public function testTheButtonStillSaysPayWhereTheCardIsCharged(): void
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW);
        $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));

        $button = (new \Symfony\Component\DomCrawler\Crawler((string) $this->client->getResponse()->getContent()))->filter('[data-nmi-pay-button]');
        self::assertSame('Pay', trim($button->text()));
    }

    /** *Neither the save option nor saved cards on a deferred method.* */
    public function testASignedInShopperWithSavedCardsIsOfferedNeither(): void
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        $paymentRequest = $this->processingRequest(storeCards: true, customer: $customer, withASavedCard: true);

        $data = $paymentRequest->getResponseData();
        self::assertTrue($data['card_on_file'] ?? null);
        self::assertArrayNotHasKey('can_store_card', $data);
        self::assertArrayNotHasKey('stored_cards', $data);
        self::assertStringNotContainsString('nmi-store-card', (string) $this->client->getResponse()->getContent());
    }

    /**
     * *Not among the shopper's saved cards* — even when the post asks for the card to be kept, which
     * the page on this method never offers, so only a hand-made request would.
     */
    public function testACardPutOnFileIsNotAmongTheShoppersSavedCards(): void
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $customer, withASavedCard: true);

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'store_card' => '1', 'cardholder_auth' => 'verified']);

        $payment = $this->reload($paymentRequest)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertNotNull($this->heldCardOf($payment), 'The card was not put on file, so the test proves nothing.');
        self::assertSame(['4242'], $this->savedCardsOf($customer), 'The saved cards changed.');
    }

    /**
     * *Not offered at a later checkout* — asked in the hardest place: the same payment method, so
     * the same gateway account, once it charges at checkout again and offers saved cards.
     */
    public function testACardPutOnFileIsNotOfferedAtALaterCheckout(): void
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $earlier = $this->processingRequest(storeCards: true, customer: $customer, withASavedCard: true);
        $this->post($earlier, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified']);
        // Read back after the request, which leaves the ones held here detached.
        $earlier = $this->reload($earlier);
        $method = $earlier->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $shopper = $earlier->getPayment()?->getOrder()?->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $shopper);
        self::assertNotNull($this->heldCardOf($earlier->getPayment()), 'The card was not put on file, so the test proves nothing.');
        $this->takePaymentLaterOn($method, false);

        $later = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW, customer: $shopper, paymentMethod: $method);
        $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $later->getId()));

        $offered = $this->reload($later)->getResponseData()['stored_cards'] ?? null;
        self::assertIsArray($offered, 'No saved cards were offered at all, so the test proves nothing.');
        self::assertSame(['4242'], array_column($offered, 'last_four'));
    }

    /** *Card on file, nothing charged.* */
    public function testAnAcceptedCardIsPutOnFileWithNothingCharged(): void
    {
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified', 'cavv' => 'Y2FyZGluYWxjb21tZXJjZWF1dGg=', 'eci' => '05']);

        self::assertResponseRedirects();
        $paymentRequest = $this->reload($paymentRequest);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertTrue($paymentRequest->getResponseData()['card_on_file'] ?? null);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AWAITING_PAYMENT, $payment->getOrder()?->getPaymentState());

        self::assertSame([], $this->gateway->operations, 'Something was charged or authorised.');
        self::assertSame(1, $this->verifier->verificationCount());
        self::assertSame(self::TOKEN, $this->verifier->lastVerification?->paymentToken);
        self::assertSame('verified', $this->verifier->lastVerification?->threeDSecure?->status);

        $card = $this->heldCardOf($payment);
        self::assertNotNull($card);
        self::assertSame(FakeNmiCardVerifier::VAULT_ID, $card->getVaultId());
        self::assertSame(FakeNmiCardVerifier::TRANSACTION_ID, $card->getInitialTransactionId());
        self::assertSame('1111', $card->getLastFour());
        $this->assertRecorded(FakeNmiCardVerifier::TRANSACTION_ID, NmiTransactionInterface::TYPE_VALIDATE);
    }

    /** *A card the gateway rejects.* */
    public function testARejectedCardLeavesNothingOnFileAndTheOrderPayable(): void
    {
        $this->verifier->willFail(new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction', 'id' => '12584700001', 'type' => 'cc', 'amount' => '0.00', 'currency' => 'USD',
            'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200',
        ], \JSON_THROW_ON_ERROR))));
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);

        $paymentRequest = $this->reload($paymentRequest);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame('jpm_martin_sylius_nmi.payment.declined', $paymentRequest->getResponseData()['message_key'] ?? null);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState(), 'The order has to stay payable with another card.');
        self::assertNull($this->heldCardOf($payment));
        $this->assertRecorded('12584700001', NmiTransactionInterface::TYPE_VALIDATE);
    }

    /** *The gateway does not answer.* */
    public function testNoAnswerLeavesNothingOnFileAndThePaymentRetryable(): void
    {
        $this->verifier->willFail(new NmiTransportException('Connection timed out'));
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);

        $paymentRequest = $this->reload($paymentRequest);
        $payment = $paymentRequest->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame('jpm_martin_sylius_nmi.payment.unreachable', $paymentRequest->getResponseData()['message_key'] ?? null);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertNull($this->heldCardOf($payment));
    }

    /**
     * A payment holding a card on file is not collected again. The pay page is the platform's own
     * doing — it looks for a payment in the `new` state and finds none — and this pins that it lands
     * on the confirmation page, not on a form.
     */
    public function testThePayPageOfAPaymentHoldingACardGoesToTheConfirmation(): void
    {
        $paymentRequest = $this->processingRequest();
        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);
        $order = $this->reload($paymentRequest)->getPayment()->getOrder();
        self::assertNotNull($order);
        $order->setTokenValue('nmi_deferred_' . bin2hex(random_bytes(4)));
        // A card is put on file after the order is placed; the fixture leaves it a cart, which the
        // platform's pay page does not look up by token at all.
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        $this->client->request('GET', sprintf('/en_US/order/%s/pay', (string) $order->getTokenValue()));

        self::assertResponseRedirects('/en_US/order/thank-you');
        self::assertSame(1, $this->verifier->verificationCount(), 'A second card was collected.');
    }

    /**
     * And the same guarantee when the page is not the way in: a request that reaches the handler for
     * a payment already holding a card is refused before the gateway is asked.
     */
    public function testASecondCardForAPaymentAlreadyHoldingOneIsRefused(): void
    {
        $first = $this->processingRequest();
        $this->post($first, ['payment_token' => self::TOKEN]);
        $payment = $this->reload($first)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);

        $second = new PaymentRequest($payment, $method);
        $second->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $second->setState(PaymentRequestInterface::STATE_PROCESSING);
        $second->setPayload(['payment_token' => self::TOKEN]);
        $this->manager->persist($second);
        $this->manager->flush();

        self::getContainer()->get('test.sylius.announcer.payment_request')->dispatchPaymentRequestCommand($second);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $second->getState());
        self::assertSame(PutCardOnFileHandler::ALREADY_ON_FILE_MESSAGE_KEY, $second->getResponseData()['message_key'] ?? null);
        self::assertSame(1, $this->verifier->verificationCount(), 'The gateway was asked to keep a second card.');
    }

    /**
     * *Confirmation, not a request to pay again.* The platform would send a waiting payment back to
     * be paid; the shopper follows the redirects a browser follows and lands on the confirmation,
     * told that nothing has been charged.
     */
    public function testTheShopperLandsOnTheConfirmationToldNothingWasCharged(): void
    {
        $paymentRequest = $this->reachThePayPageAsAShopper();
        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);

        $afterPay = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/order/after-pay/', $afterPay);

        $this->client->request('GET', $afterPay);
        self::assertResponseRedirects('/en_US/order/thank-you');

        $this->client->followRedirect();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Your card has been saved for this order. Nothing has been charged yet', $page);
        // The platform's own words for a waiting payment would be read as a charge under way.
        self::assertStringNotContainsString('Payment is being processed', $page);
    }

    /** And every other payment is still the platform's to route: a charge lands where it always did. */
    public function testAChargeAtCheckoutStillGetsThePlatformsOwnReturn(): void
    {
        $this->gateway->willApprove('12513506464');
        $paymentRequest = $this->reachThePayPageAsAShopper(takePaymentLater: false);

        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);
        $this->client->request('GET', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertResponseRedirects('/en_US/order/thank-you');

        $this->client->followRedirect();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Payment has been completed.', $page);
        self::assertStringNotContainsString('saved for this order', $page);
    }

    /** A request the pay page has prepared on a method taking payment later, reached as a browser reaches it. */
    private function processingRequest(bool $storeCards = false, ?CustomerInterface $customer = null, bool $withASavedCard = false): PaymentRequest
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW, storeCards: $storeCards, customer: $customer);
        $method = $paymentRequest->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);

        if ($withASavedCard && null !== $customer) {
            /** @var NmiStoredCardInterface $saved */
            $saved = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
            $saved->setCustomer($customer);
            $saved->setPaymentMethod($method);
            $saved->setVaultId('1730549219');
            $saved->setBrand('Visa');
            $saved->setLastFour('4242');
            $saved->setExpiryMonth(10);
            $saved->setExpiryYear(2031);
            $saved->setDefault(true);
            $this->manager->persist($saved);
            $this->manager->flush();
        }

        $crawler = $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));
        $this->csrfTokens[(string) $paymentRequest->getId()] = (string) $crawler->filter('[data-nmi-payment]')->attr('data-nmi-csrf-token');

        return $this->reload($paymentRequest);
    }

    /**
     * The way a shopper arrives: from the order's pay action, which remembers the order in the
     * session — what the confirmation page later reads — and redirects to the payment request's own
     * page. Opening that page directly, as the other tests do, skips the first half.
     */
    private function reachThePayPageAsAShopper(bool $takePaymentLater = true): PaymentRequest
    {
        $fixture = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW);
        $payment = $fixture->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method, $takePaymentLater);

        $order = $payment->getOrder();
        self::assertNotNull($order);
        $order->setTokenValue('nmi_shopper_' . bin2hex(random_bytes(4)));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        // The platform makes its own request from the order's pay action.
        $this->manager->remove($fixture);
        $this->manager->flush();

        $this->client->request('GET', sprintf('/en_US/order/%s/pay', (string) $order->getTokenValue()));
        $payPage = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/payment-request/pay/([0-9a-f-]{36})$#', $payPage);
        $hash = (string) preg_replace('#^.*/payment-request/pay/#', '', $payPage);

        $crawler = $this->client->request('GET', $payPage);
        $this->csrfTokens[$hash] = (string) $crawler->filter('[data-nmi-payment]')->attr('data-nmi-csrf-token');

        /** @var PaymentRequest $paymentRequest */
        $paymentRequest = $this->manager->find(PaymentRequest::class, $hash);

        return $paymentRequest;
    }

    private function reload(PaymentRequest $paymentRequest): PaymentRequest
    {
        /** @var PaymentRequest $reloaded */
        $reloaded = $this->manager->find(PaymentRequest::class, (string) $paymentRequest->getId());

        return $reloaded;
    }

    /** @param array<string, string> $fields */
    private function post(PaymentRequest $paymentRequest, array $fields): void
    {
        $fields['_csrf_token'] = $this->csrfTokens[(string) $paymentRequest->getId()] ?? '';

        $this->client->request('POST', sprintf('/nmi/pay/%s', (string) $paymentRequest->getId()), $fields);
    }

    /** @return list<string|null> the last four digits of every card the shopper has saved */
    private function savedCardsOf(CustomerInterface $customer): array
    {
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface $storedCards */
        $storedCards = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card');

        return array_map(static fn (NmiStoredCardInterface $card): ?string => $card->getLastFour(), $storedCards->findByCustomer($customer));
    }

    private function assertRecorded(string $transactionId, string $type): void
    {
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface $transactions */
        $transactions = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_transaction');

        self::assertSame($type, $transactions->findOneByAnyTransactionId($transactionId)?->getType());
    }
}
