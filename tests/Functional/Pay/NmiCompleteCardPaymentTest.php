<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiTransactionRepositoryInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\JpmMartin\SyliusNmiPlugin\Double\DecoratingChargeFactory;
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
        DecoratingChargeFactory::reset();

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
     * The *a decorator adds what the plugin does not model* scenario, through a decorator
     * registered in the test application the way a store registers its own: the charge the
     * gateway receives carries the store's description and merchant-defined field, and
     * everything the plugin put there.
     */
    public function testADecoratedChargeFactoryTellsTheGatewayMore(): void
    {
        DecoratingChargeFactory::$orderDescription = 'Three shirts and a cap';
        DecoratingChargeFactory::$extra = ['merchant_defined_fields' => ['1' => 'campaign-2026']];
        $this->gateway->willApprove('12513506464');
        $paymentRequest = $this->processingRequest();

        $this->post($paymentRequest, [self::TOKEN]);

        self::assertResponseRedirects();
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($paymentRequest)->getPayment()->getState());
        $charge = $this->gateway->lastCharge;
        self::assertNotNull($charge);
        self::assertSame('Three shirts and a cap', $charge->orderDescription);
        self::assertSame(['merchant_defined_fields' => ['1' => 'campaign-2026']], $charge->extra);
        self::assertSame(self::TOKEN, $charge->paymentToken, 'Everything the plugin put there is still there.');
        self::assertSame(self::AMOUNT, $charge->amount);
    }

    /**
     * What the gateway is told the order is: the order's number, which the merchant knows it by.
     *
     * Found by the first real checkout on a real store. The handler used to send the order's
     * token — sixty-four characters on an order Sylius placed — and the gateway keeps fewer than
     * fifty, so every real payment failed with a validation error, while the seeded orders of
     * the sandbox rehearsals carried short tokens and sailed through. This pins both facts.
     */
    public function testTheGatewayIsToldTheOrdersNumberNotItsToken(): void
    {
        $this->gateway->willApprove();
        $paymentRequest = $this->processingRequest();
        $order = $paymentRequest->getPayment()->getOrder();
        self::assertNotNull($order);
        $order->setNumber('000000021');
        $order->setTokenValue(bin2hex(random_bytes(32)));
        $this->manager->flush();

        $this->post($paymentRequest, [self::TOKEN]);

        self::assertNotNull($this->gateway->lastCharge);
        self::assertSame('000000021', $this->gateway->lastCharge->orderId);
        self::assertLessThan(50, strlen((string) $this->gateway->lastCharge->orderId), 'The gateway keeps fewer than fifty characters of an order reference.');
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
        self::assertCount(1, $crawler->filter('[data-nmi-payment]'), 'The card form must still be there.');
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
     * The shopper asked, and the gateway is told to keep the card.
     *
     * This is the flag on the charge, not the row in the database — nothing is stored until the
     * gateway approves, which is the next task's business.
     */
    public function testAShopperWhoAsksHasTheGatewayKeepTheCard(): void
    {
        $this->gateway->willApprove();
        $user = $this->signedInShopper();

        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        self::assertNotNull($this->gateway->lastCharge);
        self::assertTrue($this->gateway->lastCharge->storeCard);
    }

    /** And a shopper who did not ask is charged exactly as before. */
    public function testAShopperWhoDoesNotAskIsChargedWithNoVaultInstruction(): void
    {
        $this->gateway->willApprove();
        $user = $this->signedInShopper();

        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN]);

        self::assertNotNull($this->gateway->lastCharge);
        self::assertFalse($this->gateway->lastCharge->storeCard);
    }

    /**
     * **No vault path is reachable as a guest.** The page never offered the option, so a guest can
     * only be posting the flag by hand — and the answer has to come from the session rather than
     * from the absence of a checkbox in a template.
     */
    public function testAGuestWhoPostsTheFlagAnywayIsRefused(): void
    {
        $this->gateway->willApprove();
        $paymentRequest = $this->processingRequest(storeCards: true);

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        self::assertNotNull($this->gateway->lastCharge);
        self::assertFalse(
            $this->gateway->lastCharge->storeCard,
            'A guest must not be able to open a vault path by posting a field.',
        );
    }

    /** The same refusal for a signed-in shopper whose operator never turned the setting on. */
    public function testTheFlagIsIgnoredWhileTheSettingIsOff(): void
    {
        $this->gateway->willApprove();
        $user = $this->signedInShopper();

        $paymentRequest = $this->processingRequest(customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        self::assertNotNull($this->gateway->lastCharge);
        self::assertFalse($this->gateway->lastCharge->storeCard);
    }

    /**
     * *Saving alongside a successful payment.* One card, described by what the charge itself
     * returned rather than by anything this store held.
     */
    public function testAnApprovedPaymentFilesTheCardTheGatewayKept(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        $cards = $this->storedCardsOf($user->getCustomer());
        self::assertCount(1, $cards, 'Exactly one stored card, and it is the shopper\'s.');

        $card = $cards[0];
        self::assertSame('1730549219', $card->getVaultId());
        self::assertSame('12513506464', $card->getVaultingTransactionId(), 'The charge that stored it is worth citing later.');
        self::assertNull($card->getBillingId(), 'The vaulting charge returns none, and nothing here needs one.');
        self::assertSame('Visa', $card->getBrand());
        self::assertSame('1111', $card->getLastFour());
        self::assertSame(10, $card->getExpiryMonth());
        self::assertSame(2029, $card->getExpiryYear());
    }

    /**
     * *The first card becomes the default.* A list of one with nothing chosen is a choice nobody
     * made, so the first card a shopper saves is theirs by default.
     */
    public function testTheFirstCardAShopperSavesBecomesTheirDefault(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        $cards = $this->storedCardsOf($user->getCustomer());
        self::assertCount(1, $cards);
        self::assertTrue($cards[0]->isDefault(), 'The only card a shopper has is the one they pay with.');
    }

    /**
     * And the second does not quietly take over. Moving the default is a choice the shopper makes
     * from their account; paying again is not that choice.
     */
    public function testASecondSavedCardDoesNotDisplaceTheDefault(): void
    {
        $user = $this->signedInShopper();

        $this->gateway->willApproveAndKeepTheCard();
        $first = $this->processingRequest(storeCards: true, customer: $this->managedCustomer($user));
        $this->post($first, [self::TOKEN, 'store_card' => '1']);

        // A genuinely different card: same brand and expiry would be read as the same one by the
        // duplicate heuristic, and this test would then prove nothing about two rows.
        $this->gateway->willApproveAndKeepTheCard(
            vaultId: '1338089755',
            transactionId: '12518163944',
            lastFour: '4242',
        );
        // The same NMI account, deliberately: "the default" only means anything within one, so two
        // payment methods would correctly hold one default each and this would prove nothing.
        $second = $this->processingRequest(
            storeCards: true,
            customer: $this->managedCustomer($user),
            paymentMethod: $this->sameAccountAs($first),
        );
        $this->post($second, [self::TOKEN, 'store_card' => '1']);

        $cards = $this->storedCardsOf($this->managedCustomer($user));
        self::assertCount(2, $cards, 'Two different cards, two rows.');

        $defaults = array_filter($cards, static fn ($card): bool => $card->isDefault());
        self::assertCount(1, $defaults, 'Exactly one card is the default, never two and never none.');
        self::assertSame('1730549219', reset($defaults)->getVaultId(), 'And it is still the first one.');
    }

    /**
     * *Saving alongside a declined payment.* The shopper asked, the gateway refused, and nothing
     * is filed — not a half-built row, not a row with no vault reference.
     */
    public function testADeclinedPaymentLeavesNoStoredCard(): void
    {
        $this->gateway->willFail(new NmiDeclinedException($this->declined()));
        $user = $this->signedInShopper();
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        self::assertCount(0, $this->storedCardsOf($user->getCustomer()));
    }

    /**
     * An approval the gateway did not vault. It answers with an empty vault key on every charge,
     * so this is what a gateway account that refuses to store looks like from here — and the
     * payment still stands, because the shopper has already been charged.
     */
    public function testAnApprovalThatKeptNothingFilesNothingAndStillPays(): void
    {
        $this->gateway->willApprove();
        $user = $this->signedInShopper();
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN, 'store_card' => '1']);

        self::assertCount(0, $this->storedCardsOf($user->getCustomer()));
        self::assertSame(
            PaymentRequestInterface::STATE_COMPLETED,
            $this->reload($paymentRequest)->getState(),
            'A bookkeeping problem must never undo a payment that succeeded.',
        );
    }

    /** A shopper who did not ask has nothing filed, even though the gateway would have kept it. */
    public function testACardIsNotFiledUnlessTheShopperAsked(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [self::TOKEN]);

        self::assertCount(0, $this->storedCardsOf($user->getCustomer()));
    }

    /**
     * *Saving a card the shopper already has.* One row, no second vault record at the gateway,
     * and the shopper told rather than left to wonder why the box they ticked did nothing.
     *
     * The check happens **before** the charge, off what the browser's token lookup reported. It
     * has to: the gateway deduplicates nothing, so once a sale has run carrying `add_to_vault`
     * there is a second vault record and no way back from here.
     */
    public function testACardTheShopperAlreadyHasIsNotStoredTwice(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();

        $first = $this->processingRequest(storeCards: true, customer: $user->getCustomer());
        $this->post($first, [self::TOKEN, 'store_card' => '1']);
        self::assertCount(1, $this->storedCardsOf($user->getCustomer()));

        // The same card again, on a second order — described the way the browser describes it,
        // which spells the brand `visa` where the gateway spelled it `Visa`.
        $second = $this->processingRequest(
            storeCards: true,
            customer: $this->managedCustomer($user),
            paymentMethod: $this->sameAccountAs($first),
        );
        $this->post($second, [
            self::TOKEN,
            'store_card' => '1',
            'store_card_brand' => 'visa',
            'store_card_last_four' => '1111',
            'store_card_exp' => '1029',
        ]);

        self::assertCount(1, $this->storedCardsOf($user->getCustomer()), 'Still exactly one card.');

        self::assertNotNull($this->gateway->lastCharge);
        self::assertFalse(
            $this->gateway->lastCharge->storeCard,
            'The gateway must never be asked to keep a second copy of a card already on file.',
        );

        $flashes = implode(' ', $this->client->getRequest()->getSession()->getFlashBag()->peekAll()['info'] ?? []);
        self::assertStringContainsString('already have this card', $flashes, 'And the shopper is told.');
    }

    /**
     * The same card again when the browser could not describe it — its token lookup is documented
     * as optional. The gateway is asked to keep it, and the row that would duplicate an existing
     * one is still not written: the last guard is in the recorder, not in the browser.
     */
    public function testADuplicateIsStillNotWrittenWhenTheBrowserDescribedNothing(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();

        $first = $this->processingRequest(storeCards: true, customer: $user->getCustomer());
        $this->post($first, [self::TOKEN, 'store_card' => '1']);

        $second = $this->processingRequest(
            storeCards: true,
            customer: $this->managedCustomer($user),
            paymentMethod: $this->sameAccountAs($first),
        );
        $this->post($second, [self::TOKEN, 'store_card' => '1']);

        self::assertCount(1, $this->storedCardsOf($user->getCustomer()));

        self::assertNotNull($this->gateway->lastCharge);
        self::assertTrue(
            $this->gateway->lastCharge->storeCard,
            'Nothing knew it was a duplicate in time, so the gateway was asked — which is the cost of the lookup being optional.',
        );
    }

    /**
     * A browser that posts a card number where four digits belong is refused, and nothing it sent
     * is kept. The field exists so a duplicate can be spotted early; it must never become a place
     * a card number can be stored.
     */
    public function testACardNumberPostedAsTheLastFourDigitsIsRefused(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();
        $paymentRequest = $this->processingRequest(storeCards: true, customer: $user->getCustomer());

        $this->post($paymentRequest, [
            self::TOKEN,
            'store_card' => '1',
            'store_card_brand' => 'visa',
            'store_card_last_four' => '4111111111111111',
            'store_card_exp' => '1029',
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $this->reload($paymentRequest)->getPayload();
        self::assertStringNotContainsString(
            '4111111111111111',
            json_encode($payload, \JSON_THROW_ON_ERROR),
            'A card number reached the payload, which is the one place it must never be.',
        );
    }

    /** A different card is a different row, or the heuristic would be swallowing real cards. */
    public function testADifferentCardIsStoredAlongsideTheFirst(): void
    {
        $this->gateway->willApproveAndKeepTheCard();
        $user = $this->signedInShopper();

        $first = $this->processingRequest(storeCards: true, customer: $user->getCustomer());
        $this->post($first, [self::TOKEN, 'store_card' => '1']);

        $this->gateway->willApproveAndKeepTheCard('1730549220', '12513506465', 'Mastercard', '4444', '0331');
        $second = $this->processingRequest(
            storeCards: true,
            customer: $this->managedCustomer($user),
            paymentMethod: $this->sameAccountAs($first),
        );
        $this->post($second, [
            self::TOKEN,
            'store_card' => '1',
            'store_card_brand' => 'mastercard',
            'store_card_last_four' => '4444',
            'store_card_exp' => '0331',
        ]);

        self::assertCount(2, $this->storedCardsOf($user->getCustomer()));
    }

    /**
     * A request the pay page has already prepared, reached the way a shopper reaches it. Visiting
     * the page is what moves it on and what mints the token the form has to carry back, so the
     * tests below start where a browser would.
     */
    private function processingRequest(
        string $action = PaymentRequestInterface::ACTION_CAPTURE,
        bool $useAuthorize = false,
        bool $storeCards = false,
        ?CustomerInterface $customer = null,
        ?PaymentMethodInterface $paymentMethod = null,
    ): PaymentRequest {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_NEW, $action, $useAuthorize, $storeCards, $customer, $paymentMethod);

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        $this->csrfTokens[(string) $paymentRequest->getId()] = (string) $crawler
            ->filter('[data-nmi-payment]')
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

    /** The payment method an earlier request used, managed again — one card, one NMI account. */
    private function sameAccountAs(PaymentRequest $paymentRequest): PaymentMethodInterface
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->manager->find(PaymentMethod::class, (int) $paymentRequest->getMethod()->getId());

        return $paymentMethod;
    }

    /** The same customer, managed again: the reference held across an HTTP call is detached. */
    private function managedCustomer(ShopUserInterface $user): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = $this->manager->find(Customer::class, (int) $user->getCustomer()?->getId());

        return $customer;
    }

    /** A shopper with an account, already authenticated on the shop firewall. */
    private function signedInShopper(): ShopUserInterface
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');

        return $user;
    }

    /**
     * The cards on file for a customer, read back after the request.
     *
     * The reference held across the HTTP call is no longer managed, so the customer is looked up
     * again — passing the stale one would query on a detached entity and quietly find nothing.
     *
     * @return list<\JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface>
     */
    private function storedCardsOf(?CustomerInterface $customer): array
    {
        /** @var NmiStoredCardRepositoryInterface $storedCards */
        $storedCards = self::getContainer()->get('jpm_martin_sylius_nmi.repository.nmi_stored_card');

        /** @var CustomerInterface|null $managed */
        $managed = $this->manager->find(Customer::class, (int) $customer?->getId());

        return null === $managed ? [] : $storedCards->findByCustomer($managed);
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
