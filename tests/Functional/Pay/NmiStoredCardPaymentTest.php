<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\CommandHandler\CompleteCardPaymentHandler;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * Paying with a card the gateway already holds.
 *
 * Two claims run through all of it and neither can be established by reading the code. **A card is
 * charged without a card number ever existing on the page** — the posts below carry no token at
 * all, which is what makes them different from every other payment test here. And **the page is
 * not the boundary**: every refusal is asserted by posting the identifier by hand, past the markup
 * that would have prevented it.
 */
final class NmiStoredCardPaymentTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

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

    /**
     * *Paying without retyping.*
     *
     * The default is checked rather than merely present: which card is preselected is the whole
     * of what "the default" buys a shopper who has three of them.
     */
    public function testTheSavedCardsAreOfferedWithTheDefaultPreselected(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());

        $everyday = $this->aCard($paymentRequest, $user, '1111', default: true);
        $spare = $this->aCard($paymentRequest, $user, '4242');
        $this->manager->flush();

        $crawler = $this->payPage($paymentRequest);

        self::assertCount(1, $crawler->filter('#nmi-stored-cards'), 'A shopper with saved cards must be offered them.');
        self::assertTrue($this->has($this->radioFor($crawler, $everyday), 'checked'), 'The default card is the one preselected.');
        self::assertFalse($this->has($this->radioFor($crawler, $spare), 'checked'));
        self::assertFalse($this->has($this->radioFor($crawler, $spare), 'disabled'), 'A card that has not expired is selectable.');

        // The choice of typing a new card never goes away, and it is not the preselected one when
        // there is a saved card to pay with.
        self::assertCount(1, $crawler->filter('#nmi-payment-source-new'));
        self::assertFalse($this->has($crawler->filter('#nmi-payment-source-new'), 'checked'));
    }

    /**
     * *Paying without retyping*, the half that actually pays.
     *
     * Nothing is tokenised: the post carries the card's row and a CSRF token and that is all, so
     * a page that had asked the shopper to retype anything would fail this.
     */
    public function testChoosingASavedCardPaysWithoutACardNumber(): void
    {
        $this->gateway->willApprove('12513506464');

        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $card = $this->aCard($paymentRequest, $user, '1111', default: true);
        $this->manager->flush();

        $this->payPage($paymentRequest);
        $this->payWith($paymentRequest, $card);

        self::assertResponseRedirects();
        $paymentRequest = $this->reload($paymentRequest);

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $paymentRequest->getPayment()->getState());

        self::assertSame('sale', $this->gateway->lastOperation);
        self::assertNull($this->gateway->lastCharge?->paymentToken, 'A stored card is charged without a token.');
        self::assertSame('vault-1111', $this->gateway->lastCharge?->storedCard?->vaultId);
        self::assertSame('billing-1111', $this->gateway->lastCharge?->storedCard?->billingId);
    }

    /**
     * *A stored card charges like a fresh one.*
     *
     * The amount and the resulting state, compared against a payment made with a freshly typed
     * card on an identical order rather than against numbers written into this test — which is
     * what the scenario says and is the only version of it that could ever fail.
     */
    public function testAStoredCardChargesLikeAFreshOne(): void
    {
        $this->gateway->willApprove('12513506464');
        $user = $this->signedInShopper();

        $typed = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $this->manager->flush();
        $this->payPage($typed);
        $this->postTo($typed, ['payment_token' => '00000000-000000-000000-000000000000']);

        $typed = $this->reload($typed);
        $freshAmount = $this->gateway->lastCharge?->amount;
        $freshCurrency = $this->gateway->lastCharge?->currencyCode;

        $stored = $this->newPaymentRequest(
            storeCards: true,
            customer: $this->managedCustomer($user),
            paymentMethod: $this->sameAccountAs($typed),
        );
        $card = $this->aCard($stored, $user, '1111', default: true);
        $this->manager->flush();

        $this->payPage($stored);
        $this->payWith($stored, $card);
        $stored = $this->reload($stored);

        self::assertSame($freshAmount, $this->gateway->lastCharge?->amount, 'The same order is charged the same amount either way.');
        self::assertSame($freshCurrency, $this->gateway->lastCharge?->currencyCode);
        self::assertSame($typed->getState(), $stored->getState());
        self::assertSame($typed->getPayment()->getState(), $stored->getPayment()->getState());
    }

    /**
     * *Cards do not cross gateway accounts.*
     *
     * A vault reference means nothing to any NMI account but the one that issued it, so a card
     * stored under one payment method must not appear under another — nor be chargeable there by
     * anyone who posts its identifier anyway.
     */
    public function testACardStoredUnderAnotherPaymentMethodIsNeitherOfferedNorChargeable(): void
    {
        $user = $this->signedInShopper();

        $elsewhere = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $card = $this->aCard($elsewhere, $user, '1111', default: true);
        $this->manager->flush();

        // A second payment method, which `newPaymentRequest` builds on its own NMI account.
        $here = $this->newPaymentRequest(storeCards: true, customer: $this->managedCustomer($user));
        $this->manager->flush();

        $crawler = $this->payPage($here);
        self::assertCount(0, $crawler->filter('#nmi-stored-cards'), 'The other account\'s card is not this page\'s to offer.');

        $this->payWith($here, $card);

        $this->assertRefusedAsUnavailable($here);
        self::assertSame([], $this->gateway->operations, 'The gateway must never be asked to charge a card from another account.');
    }

    /**
     * *An expired stored card.*
     *
     * Shown, so a shopper looking for it learns why it is not a choice, and unselectable — in the
     * markup and again on the way in, because a disabled radio is a courtesy rather than a rule.
     */
    public function testAnExpiredCardIsShownAsExpiredAndCannotBeChosen(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());

        $expired = $this->aCard($paymentRequest, $user, '1111', default: true, expiryYear: 2020);
        $usable = $this->aCard($paymentRequest, $user, '4242');
        $this->manager->flush();

        $crawler = $this->payPage($paymentRequest);

        self::assertTrue($this->has($this->radioFor($crawler, $expired), 'disabled'));
        self::assertCount(
            1,
            $crawler->filter(sprintf('[data-test-nmi-stored-card="%d"] [data-test-nmi-stored-card-expired]', (int) $expired->getId())),
            'The card is listed and labelled expired rather than quietly dropped.',
        );

        // The default has expired, so the preselection falls to the card that can still pay.
        self::assertFalse($this->has($this->radioFor($crawler, $expired), 'checked'));
        self::assertTrue($this->has($this->radioFor($crawler, $usable), 'checked'));

        $this->payWith($paymentRequest, $expired);

        $this->assertRefusedAsUnavailable($paymentRequest);
        self::assertSame([], $this->gateway->operations, 'An expired card must not reach the gateway.');
    }

    /**
     * The one case where every saved card has expired: the new-card form is what is preselected,
     * because a shopper whose only card is dead still has to be able to pay.
     */
    public function testWhenEveryCardHasExpiredTheNewCardFormIsPreselected(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $this->aCard($paymentRequest, $user, '1111', default: true, expiryYear: 2020);
        $this->manager->flush();

        $crawler = $this->payPage($paymentRequest);

        self::assertTrue($this->has($crawler->filter('#nmi-payment-source-new'), 'checked'));
    }

    /**
     * Another shopper's card, posted by its row.
     *
     * The account area refuses this too, but the pay page is a second door onto the same rows and
     * a boundary that holds on one route and not the other is not a boundary.
     */
    public function testAnotherShoppersCardCannotBeCharged(): void
    {
        $stranger = $this->signedInShopper();
        $theirRequest = $this->newPaymentRequest(storeCards: true, customer: $stranger->getCustomer());
        $theirCard = $this->aCard($theirRequest, $stranger, '1111', default: true);
        $this->manager->flush();

        $me = $this->signedInShopper();
        $myRequest = $this->newPaymentRequest(
            storeCards: true,
            customer: $me->getCustomer(),
            paymentMethod: $this->sameAccountAs($theirRequest),
        );
        $this->manager->flush();

        $this->payPage($myRequest);
        $this->payWith($myRequest, $theirCard);

        $this->assertRefusedAsUnavailable($myRequest);
        self::assertSame([], $this->gateway->operations);
    }

    /**
     * A guest never has saved cards, and posting an identifier from a signed-out session must not
     * charge one. The order carries a customer — every guest order does — which is exactly why
     * nothing here decides from the order.
     */
    public function testAGuestCannotPayWithASavedCard(): void
    {
        $user = $this->signedInShopper();
        $theirRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $card = $this->aCard($theirRequest, $user, '1111', default: true);
        $this->manager->flush();

        $guestRequest = $this->newPaymentRequest(
            storeCards: true,
            customer: $this->managedCustomer($user),
            paymentMethod: $this->sameAccountAs($theirRequest),
        );
        $this->manager->flush();

        $crawler = $this->payPage($guestRequest);
        self::assertCount(1, $crawler->filter('#nmi-stored-cards'), 'Signed in, the cards are there.');

        // Signed out, on the same order, with the same identifier.
        $this->client->request('GET', '/en_US/logout');
        $this->payWith($guestRequest, $card);

        $this->assertRefusedAsUnavailable($guestRequest);
        self::assertSame([], $this->gateway->operations);
    }

    /**
     * *Authentication enabled*, the half a server can hold to account.
     *
     * The vault reference is what the browser authenticates, and this is the only way it can
     * obtain one. So this is the test that says where that reference may go: to the card's owner,
     * over an authenticated POST carrying the page's own token, and nowhere else.
     */
    public function testTheVaultReferenceIsHandedOverOnlyToTheCardsOwner(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $card = $this->aCard($paymentRequest, $user, '1111', default: true);
        $this->manager->flush();

        $crawler = $this->payPage($paymentRequest);

        // Asked for, never rendered. This is the assertion the whole route exists for.
        self::assertStringNotContainsString('vault-1111', (string) $this->client->getResponse()->getContent());
        self::assertSame('1', $crawler->filter('#nmi-stored-cards')->attr('data-nmi-authenticate'));

        $this->askToAuthenticate($paymentRequest, (string) $card->getId());

        self::assertResponseIsSuccessful();
        // Asserted by what it forbids rather than by the exact string, which Symfony reorders and
        // supplements: what matters is that nothing may keep a copy of a vault reference.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);

        /** @var array<string, string> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('vault-1111', $answer['customer_vault_id']);
        self::assertSame('12.99', $answer['amount'], 'The decimal amount the authentication call wants.');
        self::assertSame('USD', $answer['currency']);
    }

    /**
     * *Authentication disabled.*
     *
     * The setting closes the door rather than only making the browser skip a step: a store that
     * turned authentication off has no use for the reference, so there is no request that obtains
     * it. The page says so too, which is what stops the browser asking.
     */
    public function testWithAuthenticationOffTheReferenceCannotBeObtainedAtAll(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(
            storeCards: true,
            customer: $user->getCustomer(),
            authenticateStoredCards: false,
        );
        $card = $this->aCard($paymentRequest, $user, '1111', default: true);
        $this->manager->flush();

        $crawler = $this->payPage($paymentRequest);
        self::assertNull($crawler->filter('#nmi-stored-cards')->attr('data-nmi-authenticate'));

        $this->askToAuthenticate($paymentRequest, (string) $card->getId());
        self::assertResponseStatusCodeSame(404);

        // And the charge still goes through, marked as a stored credential, with no authentication
        // step anywhere in it.
        $this->gateway->willApprove('12513506464');
        $this->payWith($paymentRequest, $card);

        self::assertSame('sale', $this->gateway->lastOperation);
        self::assertNull($this->gateway->lastCharge?->threeDSecure, 'Nothing authenticated it, so nothing is claimed to have.');
        self::assertSame('vault-1111', $this->gateway->lastCharge?->storedCard?->vaultId);
    }

    /** Another shopper's card yields nothing here either — the same service refuses both doors. */
    public function testAnotherShoppersVaultReferenceIsNotHandedOver(): void
    {
        $stranger = $this->signedInShopper();
        $theirRequest = $this->newPaymentRequest(storeCards: true, customer: $stranger->getCustomer());
        $theirCard = $this->aCard($theirRequest, $stranger, '1111', default: true);
        $this->manager->flush();

        $me = $this->signedInShopper();
        $myRequest = $this->newPaymentRequest(
            storeCards: true,
            customer: $me->getCustomer(),
            paymentMethod: $this->sameAccountAs($theirRequest),
        );
        $this->manager->flush();

        $this->askToAuthenticate($myRequest, (string) $theirCard->getId());

        // The 404 is the whole assertion: there is no JSON body to read a reference out of. An
        // earlier version also checked the body for the reference and passed for the wrong reason
        // — the test environment's error page renders the failing test's own source, so a literal
        // written into the assertion turns up in the page it is asserting about.
        self::assertResponseStatusCodeSame(404);
    }

    /** An expired card cannot be authenticated for the same reason it cannot be charged. */
    public function testAnExpiredCardsReferenceIsNotHandedOver(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $card = $this->aCard($paymentRequest, $user, '1111', default: true, expiryYear: 2020);
        $this->manager->flush();

        $this->askToAuthenticate($paymentRequest, (string) $card->getId());

        self::assertResponseStatusCodeSame(404);
    }

    /** Without the page's own token this is a request from somewhere else, and it is refused. */
    public function testTheReferenceIsNotHandedOverWithoutTheFormsToken(): void
    {
        $user = $this->signedInShopper();
        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());
        $card = $this->aCard($paymentRequest, $user, '1111', default: true);
        $this->manager->flush();

        $this->payPage($paymentRequest);
        $this->client->request(
            'POST',
            sprintf('/nmi/pay/%s/authenticate', (string) $paymentRequest->getId()),
            ['stored_card' => (string) $card->getId(), '_csrf_token' => 'not-the-token'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Refused because the card cannot be charged — not merely failed.
     *
     * Worth the extra assertion: a request that failed for any other reason would satisfy a bare
     * state check while sending the shopper, and whoever reads the row afterwards, to look at the
     * wrong thing entirely.
     */
    private function assertRefusedAsUnavailable(PaymentRequest $paymentRequest): void
    {
        $reloaded = $this->reload($paymentRequest);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $reloaded->getState());
        self::assertSame(
            CompleteCardPaymentHandler::CARD_UNAVAILABLE_MESSAGE_KEY,
            $reloaded->getResponseData()['message_key'] ?? null,
        );
    }

    /** Asks the store for what the browser would need in order to authenticate a saved card. */
    private function askToAuthenticate(PaymentRequest $paymentRequest, string $storedCardId): void
    {
        $crawler = $this->payPage($paymentRequest);

        $this->client->request(
            'POST',
            sprintf('/nmi/pay/%s/authenticate', (string) $paymentRequest->getId()),
            [
                'stored_card' => $storedCardId,
                '_csrf_token' => (string) $crawler->filter('#nmi-payment')->attr('data-nmi-csrf-token'),
            ],
        );
    }

    private function payPage(PaymentRequest $paymentRequest): Crawler
    {
        return $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );
    }

    /** Posts the choice the saved-card button posts, and nothing else — no token of any kind. */
    private function payWith(PaymentRequest $paymentRequest, NmiStoredCardInterface $card): void
    {
        $this->postTo($paymentRequest, ['stored_card' => (string) $card->getId()]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function postTo(PaymentRequest $paymentRequest, array $fields): void
    {
        $crawler = $this->payPage($paymentRequest);

        // The token the page rendered, taken from the page rather than minted here: a form that
        // stopped carrying one would then fail these tests rather than pass them.
        $fields['_csrf_token'] = (string) $crawler->filter('#nmi-payment')->attr('data-nmi-csrf-token');

        $this->client->request('POST', sprintf('/nmi/pay/%s', (string) $paymentRequest->getId()), $fields);
    }

    /**
     * Whether a valueless HTML attribute is on the element.
     *
     * `checked` and `disabled` are written bare, and the crawler reports a bare attribute as an
     * empty string — so asking whether it equals its own name would fail on markup that is right.
     */
    private function has(Crawler $element, string $attribute): bool
    {
        return null !== $element->attr($attribute);
    }

    private function radioFor(Crawler $crawler, NmiStoredCardInterface $card): Crawler
    {
        return $crawler->filter(sprintf('#nmi-stored-card-%d', (int) $card->getId()));
    }

    private function signedInShopper(): ShopUserInterface
    {
        $user = $this->newShopUser(sprintf('ada-%s@example.com', bin2hex(random_bytes(6))));
        $this->client->loginUser($user, 'shop');

        return $user;
    }

    /** The same customer, managed again: a reference held across an HTTP call is detached. */
    private function managedCustomer(ShopUserInterface $user): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = $this->manager->find(Customer::class, (int) $user->getCustomer()?->getId());

        return $customer;
    }

    /** The payment method an earlier request used, managed again — one card, one NMI account. */
    private function sameAccountAs(PaymentRequest $paymentRequest): PaymentMethodInterface
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->manager->find(PaymentMethod::class, (int) $paymentRequest->getMethod()->getId());

        return $paymentMethod;
    }

    private function aCard(
        PaymentRequest $paymentRequest,
        ShopUserInterface $user,
        string $lastFour,
        bool $default = false,
        int $expiryYear = 2030,
    ): NmiStoredCardInterface {
        /** @var NmiStoredCardInterface $card */
        $card = self::getContainer()->get('jpm_martin_sylius_nmi.factory.nmi_stored_card')->createNew();
        $card->setCustomer($this->managedCustomer($user));
        $card->setPaymentMethod($this->sameAccountAs($paymentRequest));
        $card->setVaultId('vault-' . $lastFour);
        $card->setBillingId('billing-' . $lastFour);
        $card->setBrand('visa');
        $card->setLastFour($lastFour);
        $card->setExpiryMonth(10);
        $card->setExpiryYear($expiryYear);
        $card->setDefault($default);
        $this->manager->persist($card);

        return $card;
    }

    private function reload(PaymentRequest $paymentRequest): PaymentRequest
    {
        /** @var PaymentRequest $reloaded */
        $reloaded = $this->manager->find(PaymentRequest::class, (string) $paymentRequest->getId());

        return $reloaded;
    }
}
