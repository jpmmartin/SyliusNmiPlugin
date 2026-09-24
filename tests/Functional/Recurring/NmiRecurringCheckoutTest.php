<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Recurring;

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
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Double\DecoratingRecurringChargesPolicy;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiCardVerifier;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\CardOnFile\TakesPaymentLater;
use Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay\BuildsAnNmiPaymentRequest;

/**
 * The checkout of a payment the store says opens recurring charges, reached the way a shopper
 * reaches it: the pay page is opened, which prepares the request and mints the form's token, and
 * the card is posted to the plugin's own endpoint.
 */
final class NmiRecurringCheckoutTest extends WebTestCase
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
        // The store's policy says yes unless a test says otherwise.
        DecoratingRecurringChargesPolicy::$answer = true;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();
        DecoratingRecurringChargesPolicy::reset();

        parent::tearDown();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    /** *The commitment is shown before paying* — the client is told, on the page's own request. */
    public function testThePreparedRequestSaysThePaymentOpensRecurringCharges(): void
    {
        $data = $this->preparedRequest()->getResponseData();

        self::assertTrue($data['recurring_charges'] ?? null);
        self::assertArrayNotHasKey('card_on_file', $data, 'Charged now: nothing is held.');
        self::assertSame(self::TOKENIZATION_KEY, $data['tokenization_key'] ?? null);
    }

    /** *Neither the option to save the card nor any saved card is offered.* */
    public function testASignedInShopperWithSavedCardsIsOfferedNeither(): void
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);

        $data = $this->preparedRequest(storeCards: true, customer: $customer, withASavedCard: true)->getResponseData();

        self::assertTrue($data['recurring_charges'] ?? null);
        self::assertArrayNotHasKey('can_store_card', $data);
        self::assertArrayNotHasKey('stored_cards', $data);
    }

    /** On a method taking payment later the payment is held as well, and says both. */
    public function testAHeldPaymentSaysBoth(): void
    {
        $data = $this->preparedRequest(takePaymentLater: true)->getResponseData();

        self::assertTrue($data['card_on_file'] ?? null);
        self::assertTrue($data['recurring_charges'] ?? null);
    }

    /** *A payment that opens nothing is unchanged.* */
    public function testAPaymentThatOpensNothingIsPreparedAsBefore(): void
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        DecoratingRecurringChargesPolicy::$answer = false;

        $data = $this->preparedRequest(storeCards: true, customer: $customer, withASavedCard: true)->getResponseData();

        self::assertArrayNotHasKey('recurring_charges', $data);
        self::assertTrue($data['can_store_card'] ?? null);
        self::assertCount(1, $data['stored_cards'] ?? []);
    }

    /** *Charged at checkout.* */
    public function testAChargeNowKeepsARecurringCredentialCitingTheSale(): void
    {
        $this->gateway->willApproveAndKeepTheCard(vaultId: '1736036779', transactionId: '12592792816', brand: 'Visa', lastFour: '1111', expiry: '1029');
        $paymentRequest = $this->preparedRequest();

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified']);

        $charge = $this->gateway->lastCharge;
        self::assertNotNull($charge);
        self::assertTrue($charge->opensStoredCredential, 'The sale must be declared the first use of a stored credential.');
        self::assertFalse($charge->storeCard, 'Not kept for the shopper: a saved card is a different promise.');
        self::assertSame(['sale'], $this->gateway->operations);

        $payment = $this->reload($paymentRequest)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());

        $credential = $this->credentialOpenedBy($payment);
        self::assertNotNull($credential);
        self::assertSame('1736036779', $credential->getVaultId());
        self::assertSame('12592792816', $credential->getInitialTransactionId());
        self::assertSame($payment->getMethod(), $credential->getPaymentMethod());
        self::assertSame($payment->getOrder()?->getCustomer(), $credential->getCustomer());
        self::assertSame(['Visa', '1111', 10, 2029], [$credential->getBrand(), $credential->getLastFour(), $credential->getExpiryMonth(), $credential->getExpiryYear()]);
        $this->assertRecorded('12592792816', NmiTransactionInterface::TYPE_SALE);
    }

    /** *Authorised first.* */
    public function testAuthorisingFirstKeepsARecurringCredentialCitingTheAuthorisation(): void
    {
        $this->gateway->willApproveAndKeepTheCard(vaultId: '1736035505', transactionId: '12592793052');
        $paymentRequest = $this->preparedRequest(useAuthorize: true);

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified']);

        self::assertSame(['authorize'], $this->gateway->operations);
        self::assertTrue($this->gateway->lastCharge?->opensStoredCredential);
        $payment = $this->reload($paymentRequest)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame('12592793052', $this->credentialOpenedBy($payment)?->getInitialTransactionId());
    }

    /** *The gateway declines* — nothing is kept, as for any payment. */
    public function testADeclineKeepsNoCredential(): void
    {
        $this->gateway->willFail(new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction', 'id' => '12584700002', 'type' => 'cc', 'amount' => '109.51', 'currency' => 'USD',
            'response' => '2', 'response_text' => 'DECLINE', 'response_code' => '200',
        ], \JSON_THROW_ON_ERROR))));
        $paymentRequest = $this->preparedRequest();

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified']);

        $payment = $this->reload($paymentRequest)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertNull($this->credentialOpenedBy($payment));
        self::assertNotSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function testNoAnswerKeepsNoCredential(): void
    {
        $this->gateway->willFail(NmiTransportException::fromInconclusiveStatus(503));
        $paymentRequest = $this->preparedRequest();

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified']);

        $payment = $this->reload($paymentRequest)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertNull($this->credentialOpenedBy($payment));
    }

    /** A saved card is never charged on this promise — only a hand-made request would send one. */
    public function testASavedCardSentByHandIsRefusedAndOpensNothing(): void
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');
        $customer = $user->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $paymentRequest = $this->preparedRequest(storeCards: true, customer: $customer, withASavedCard: true);
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface $storedCards */
        $storedCards = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card');
        $saved = $storedCards->findByCustomer($customer)[0] ?? null;
        self::assertNotNull($saved);

        $this->post($paymentRequest, ['stored_card' => (string) $saved->getId()]);

        self::assertSame([], $this->gateway->operations, 'The gateway was asked to charge a saved card.');
        $payment = $this->reload($paymentRequest)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertNull($this->credentialOpenedBy($payment));
    }

    /** *Taking payment later* — the held payment keeps a recurring credential, and no card on file. */
    public function testATakePaymentLaterCheckoutKeepsACredentialInsteadOfACardOnFile(): void
    {
        $this->verifier->willVerify(transactionId: '12592792407', vaultId: '1736036779');
        $paymentRequest = $this->preparedRequest(takePaymentLater: true);

        $this->post($paymentRequest, ['payment_token' => self::TOKEN, 'cardholder_auth' => 'verified']);

        $reloaded = $this->reload($paymentRequest);
        $payment = $reloaded->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState(), 'Held: nothing charged or reserved.');
        self::assertSame([], $this->gateway->operations, 'Nothing was to be charged.');
        self::assertNull($this->heldCardOf($payment), 'A card on file as well would be a second promise on one card.');
        $credential = $this->credentialOpenedBy($payment);
        self::assertNotNull($credential);
        self::assertSame('12592792407', $credential->getInitialTransactionId());
        self::assertTrue($reloaded->getResponseData()['card_on_file'] ?? null);
        self::assertTrue($reloaded->getResponseData()['recurring_charges'] ?? null);
        $this->assertRecorded('12592792407', NmiTransactionInterface::TYPE_VALIDATE);
    }

    /** *Returning to the pay page* — a held payment kept on a credential is not collected again. */
    public function testASecondCardForAHeldPaymentKeptOnACredentialIsRefused(): void
    {
        $first = $this->preparedRequest(takePaymentLater: true);
        $this->post($first, ['payment_token' => self::TOKEN]);
        $payment = $this->reload($first)->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertNotNull($this->credentialOpenedBy($payment), 'Nothing was kept, so the test proves nothing.');
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

    public function testThePayPageOfAHeldPaymentKeptOnACredentialGoesToTheConfirmation(): void
    {
        $paymentRequest = $this->preparedRequest(takePaymentLater: true);
        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);
        $order = $this->reload($paymentRequest)->getPayment()->getOrder();
        self::assertNotNull($order);
        $order->setTokenValue('nmi_recurring_' . bin2hex(random_bytes(4)));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $this->manager->flush();

        $this->client->request('GET', sprintf('/en_US/order/%s/pay', (string) $order->getTokenValue()));

        self::assertResponseRedirects('/en_US/order/thank-you');
        self::assertSame(1, $this->verifier->verificationCount(), 'A second card was collected.');
    }

    /** The shopper is told the card was kept for this order and its renewals, and nothing charged yet. */
    public function testTheShopperIsToldTheCardWasKeptForThisOrderAndItsRenewals(): void
    {
        $paymentRequest = $this->reachThePayPageAsAShopper();
        $this->post($paymentRequest, ['payment_token' => self::TOKEN]);

        $afterPay = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/order/after-pay/', $afterPay);
        $this->client->request('GET', $afterPay);
        self::assertResponseRedirects('/en_US/order/thank-you');

        $this->client->followRedirect();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Your card has been saved for this order and its renewals. Nothing has been charged yet', $page);
        self::assertStringNotContainsString('Payment is being processed', $page);
    }

    /** *The store's own wording* — what the shopper reads before paying is the store's translation. */
    public function testTheStoresOwnWordingReplacesTheDefaultStatement(): void
    {
        // The catalogue is where a store's own translation file ends up. Written into it here
        // rather than into a file because catalogues are cached, and a file added now is not read.
        /** @var TranslatorBagInterface $translator */
        $translator = self::getContainer()->get('translator');
        $translator->getCatalogue('en_US')->set(
            'jpm_martin_sylius_nmi.shop.pay.recurring_commitment',
            'Renewed every month at the price shown. Cancel any time from your account.',
        );

        $this->reachThePayPageAsAShopper();

        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Renewed every month at the price shown. Cancel any time from your account.', $page);
        self::assertStringNotContainsString('This card will be kept so that the store can charge it again', $page);
    }

    private function preparedRequest(
        bool $storeCards = false,
        ?CustomerInterface $customer = null,
        bool $withASavedCard = false,
        bool $takePaymentLater = false,
        bool $useAuthorize = false,
    ): PaymentRequest {
        // As the platform does when it makes the request: the action follows the method's setting.
        $paymentRequest = $this->newPaymentRequest(
            PaymentRequestInterface::STATE_NEW,
            $useAuthorize ? PaymentRequestInterface::ACTION_AUTHORIZE : PaymentRequestInterface::ACTION_CAPTURE,
            useAuthorize: $useAuthorize,
            storeCards: $storeCards,
            customer: $customer ?? $this->aGuest(),
        );
        $method = $paymentRequest->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method, $takePaymentLater);

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
     * page. On a method taking payment later, for a payment that opens recurring charges.
     */
    private function reachThePayPageAsAShopper(): PaymentRequest
    {
        $fixture = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW, customer: $this->aGuest());
        $payment = $fixture->getPayment();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $method = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $method);
        $this->takePaymentLaterOn($method);

        $order = $payment->getOrder();
        self::assertNotNull($order);
        $order->setTokenValue('nmi_shopper_' . bin2hex(random_bytes(4)));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
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

    private function credentialOpenedBy(PaymentInterface $payment): ?\JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface
    {
        /** @var \JpmMartin\SyliusNmiPlugin\Repository\NmiRecurringCredentialRepositoryInterface $credentials */
        $credentials = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_recurring_credential');

        return $credentials->findOpenedBy($payment);
    }

    /** A guest order has a customer too, which is what a credential belongs to. */
    private function aGuest(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = self::getContainer()->get('sylius.factory.customer')->createNew();
        $customer->setEmail(sprintf('guest+%s@example.com', bin2hex(random_bytes(4))));
        $this->manager->persist($customer);

        return $customer;
    }
}
