<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The pay page end to end: the platform announces the request's command, the first-phase handler
 * writes what the browser needs and moves the request on, and only then is the form rendered.
 *
 * Nothing here touches the gateway. That is the point — the first phase must not, because the
 * shopper has not entered a card yet.
 */
final class NmiPayPageTest extends WebTestCase
{
    use BuildsAnNmiPaymentRequest;

    private const TOKENIZATION_KEY = 'tok-public-0123';

    private const SECURITY_KEY = 'sec-private-4567';

    private const AMOUNT = 1299;

    private KernelBrowser $client;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Without this the kernel is rebooted for every request, which would hand the request a
        // different entity manager from the one holding this test's open transaction.
        $this->client->disableReboot();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testThePayPageMovesTheRequestOnAndRendersWhatTheBrowserNeeds(): void
    {
        $paymentRequest = $this->newPaymentRequest();
        $hash = (string) $paymentRequest->getId();

        self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());

        $crawler = $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', $hash));

        $this->manager->refresh($paymentRequest);
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState(), 'The first-phase handler must move the request on.');

        self::assertResponseIsSuccessful();

        $responseData = $paymentRequest->getResponseData();
        self::assertSame(self::TOKENIZATION_KEY, $responseData['tokenization_key']);
        self::assertSame(1299, $responseData['amount']);
        self::assertSame('USD', $responseData['currency_code']);
        self::assertSame(PaymentRequestInterface::ACTION_CAPTURE, $responseData['action']);

        $mount = $crawler->filter('[data-nmi-payment]');
        self::assertCount(1, $mount, 'The page must carry one mount point for the browser component.');
        // The *same margins as the checkout* scenario: inside the theme's content container, like
        // every other page of the store, rather than against the edge of the viewport.
        self::assertCount(1, $crawler->filter('.container .row .col [data-nmi-payment]'), 'The pay page must sit inside the theme\'s content container.');
        self::assertSame(self::TOKENIZATION_KEY, $mount->attr('data-nmi-tokenization-key'));
        self::assertSame('1299', $mount->attr('data-nmi-amount'));
        self::assertSame('USD', $mount->attr('data-nmi-currency'));

        // The private key must never reach the browser.
        self::assertStringNotContainsString(self::SECURITY_KEY, (string) $this->client->getResponse()->getContent());
    }

    /**
     * The *card fields are the theme's inputs* scenario, as far as markup can show it: the form is
     * the theme's own — a label above each of the three elements the gateway's frames go into,
     * and the theme's primary button — rather than something the script draws. The button starts
     * disabled, because only the script knows when the frames are ready to take a card.
     */
    public function testTheCardFormIsTheThemesOwnMarkup(): void
    {
        $paymentRequest = $this->newPaymentRequest();

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        foreach (['#nmi-card-number' => 'ccnumber', '#nmi-card-expiry' => 'ccexp', '#nmi-card-cvv' => 'cvv'] as $id => $field) {
            $element = $crawler->filter('[data-nmi-payment] ' . $id);
            self::assertCount(1, $element, sprintf('%s is where the gateway puts its %s frame.', $id, $field));
            self::assertSame($field, $element->attr('data-nmi-field'));
            self::assertNotSame('', (string) $element->attr('data-nmi-title'), 'The frame\'s accessible name comes off the element, translated.');
        }
        self::assertSame('Card number', $crawler->filter('#nmi-card-number')->attr('data-nmi-title'));
        self::assertCount(3, $crawler->filter('[data-nmi-payment] label.form-label'), 'A label above each field, as the theme draws a form.');

        $button = $crawler->filter('[data-nmi-pay-button]');
        self::assertCount(1, $button, 'The pay button is a hookable of its own.');
        self::assertStringContainsString('btn-primary', (string) $button->attr('class'));
        self::assertNotNull($button->attr('disabled'), 'Enabled by the script once the frames are ready, never before.');
        self::assertNotNull($button->attr('data-nmi-new-card-only'), 'Choosing a saved card must put the button away along with the fields.');
        self::assertSame('Pay', trim($button->text()));

        $error = $crawler->filter('[data-nmi-error]');
        self::assertCount(1, $error, 'Somewhere for a failed attempt to be said.');
        self::assertNotNull($error->attr('hidden'));
    }

    /**
     * The page must still be a shop page.
     *
     * Everything else here asserts markup this plugin renders, which would all keep passing on a
     * page whose card fields never mount: the mount point is ours, the JavaScript that fills it is
     * the store's. This is the one assertion that fails if the pay page stops inheriting the
     * store's own scripts.
     */
    public function testThePayPageStillGetsTheStoresOwnScripts(): void
    {
        $paymentRequest = $this->newPaymentRequest();

        $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        $html = (string) $this->client->getResponse()->getContent();

        self::assertMatchesRegularExpression(
            '#<script[^>]+src="[^"]*plugin-shop-entry\.js#',
            $html,
            'Without the build that mounts the card fields, the page renders and does nothing.',
        );
    }

    /**
     * Authentication happens in the browser, and the issuer decides whether to challenge from what
     * it is told about the shopper. The page therefore has to carry the decimal amount and the
     * cardholder, neither of which the charge itself needs.
     */
    public function testThePageCarriesWhatAuthenticationNeeds(): void
    {
        $paymentRequest = $this->newPaymentRequest();

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        self::assertCount(1, $crawler->filter('[data-nmi-three-d-secure]'), 'The challenge needs somewhere to render.');

        $mount = $crawler->filter('[data-nmi-payment]');
        self::assertSame('12.99', $mount->attr('data-nmi-amount-major'));
        self::assertSame('Ada', $mount->attr('data-nmi-first-name'));
        self::assertSame('Lovelace', $mount->attr('data-nmi-last-name'));
        self::assertSame('London', $mount->attr('data-nmi-city'));
        self::assertSame('GB', $mount->attr('data-nmi-country'));
    }

    /**
     * The page in the shopper's own language.
     *
     * The plugin ships two catalogues, and a catalogue nothing renders is a claim rather than a
     * feature: it can drift from the templates for a whole release without anything noticing.
     * This asks the store for the same page in the other locale and reads the words back.
     */
    public function testThePayPageRendersInTheShoppersLanguage(): void
    {
        $paymentRequest = $this->newPaymentRequest();
        $this->alsoSpeaks($paymentRequest, 'es_ES');
        $hash = (string) $paymentRequest->getId();

        $spanish = $this->client->request('GET', sprintf('/es_ES/payment-request/pay/%s', $hash));
        self::assertResponseIsSuccessful();
        // The page's own heading, in the content column `nmi.html.twig` wraps the hook in: the
        // theme's header carries an `h1` of its own for the taxon menu as soon as the store has
        // taxons, and a plain `h1` would read that one first.
        self::assertStringContainsString('Pagar con tarjeta', $spanish->filter('.col > h1')->text());

        // The message the script shows when it cannot read the card is rendered by the template,
        // not by the JavaScript, which is the only reason it can be translated. So are the labels
        // and the frames' accessible names.
        self::assertSame(
            'No se han podido leer los datos de la tarjeta. Revísalos e inténtalo de nuevo.',
            $spanish->filter('[data-nmi-payment]')->attr('data-nmi-error-message'),
        );
        self::assertSame('Número de tarjeta', $spanish->filter('#nmi-card-number')->attr('data-nmi-title'));
        self::assertSame('Número de tarjeta', trim($spanish->filter('[data-nmi-payment] label.form-label')->first()->text()));
        self::assertSame('Pagar', trim($spanish->filter('[data-nmi-pay-button]')->text()));
    }

    /** A finished request has nothing left to collect, so the platform sends the shopper onward. */
    public function testAFinishedRequestIsNotGivenACardForm(): void
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED);

        $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));

        self::assertResponseRedirects();
    }

    /**
     * The option to keep the card. Offered to a shopper who is signed in on a gateway whose
     * operator turned it on, and to nobody else — the three tests below are the three ways that
     * sentence can fail.
     */
    public function testASignedInShopperIsOfferedTheOptionUnticked(): void
    {
        $user = $this->newShopUser($this->anEmail());
        $this->client->loginUser($user, 'shop');

        $paymentRequest = $this->newPaymentRequest(storeCards: true, customer: $user->getCustomer());

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        $checkbox = $crawler->filter('#nmi-store-card');
        self::assertCount(1, $checkbox, 'A signed-in shopper must be offered the option.');
        self::assertNull($checkbox->attr('checked'), 'Nothing is kept unless the shopper asks for it.');

        $this->manager->refresh($paymentRequest);
        self::assertTrue($paymentRequest->getResponseData()['can_store_card'] ?? false);
    }

    /**
     * A guest checkout has a customer of its own in a real store, so this is not merely about the
     * template: the answer comes from who is signed in, and nobody is.
     */
    public function testAGuestIsNotOfferedTheOption(): void
    {
        $paymentRequest = $this->newPaymentRequest(storeCards: true);

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        self::assertCount(0, $crawler->filter('#nmi-store-card'), 'A guest must not be offered the option.');

        $this->manager->refresh($paymentRequest);
        self::assertArrayNotHasKey(
            'can_store_card',
            $paymentRequest->getResponseData(),
            'And the API answer must not mention it either.',
        );
    }

    /**
     * The *Card saving left disabled* scenario. A signed-in shopper on a gateway whose operator
     * never turned it on sees the page exactly as it was before this feature existed.
     */
    public function testNothingIsOfferedWhileTheSettingIsOff(): void
    {
        $user = $this->newShopUser($this->anEmail());
        $this->client->loginUser($user, 'shop');

        $paymentRequest = $this->newPaymentRequest(customer: $user->getCustomer());

        $crawler = $this->client->request(
            'GET',
            sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()),
        );

        self::assertCount(0, $crawler->filter('#nmi-store-card'));

        $this->manager->refresh($paymentRequest);
        self::assertArrayNotHasKey('can_store_card', $paymentRequest->getResponseData());
    }

    /** Unique per test: the fixtures roll back, but two users inside one test would collide. */
    private function anEmail(): string
    {
        return sprintf('ada-%s@example.com', bin2hex(random_bytes(6)));
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }

    /**
     * Enables one more locale on the channel this request's order belongs to, and points the client
     * at that channel by a hostname of its own.
     *
     * `newPaymentRequest()` gives every channel it builds the hostname `localhost`, which is what the
     * client asks for. On a database that already has a channel answering there — the fixtures'
     * `FASHION_WEB`, once `composer database-reset` has run — Sylius resolves that one instead, it
     * does not speak the new locale, and the shop redirects to its own default locale. CI, with no
     * fixtures, never saw it. So the channel that learnt the locale is the one the request reaches.
     */
    private function alsoSpeaks(PaymentRequestInterface $paymentRequest, string $code): void
    {
        $locale = $this->manager->getRepository(Locale::class)->findOneBy(['code' => $code]) ?? new Locale();
        $locale->setCode($code);
        $this->manager->persist($locale);

        /** @var OrderInterface $order */
        $order = $paymentRequest->getPayment()->getOrder();
        $channel = $order->getChannel();
        self::assertNotNull($channel);
        $channel->addLocale($locale);
        $channel->setHostname($channel->getCode() . '.localhost');

        $this->manager->flush();

        $this->client->setServerParameter('HTTP_HOST', (string) $channel->getHostname());
    }
}
