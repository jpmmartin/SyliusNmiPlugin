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

        $mount = $crawler->filter('#nmi-payment');
        self::assertCount(1, $mount, 'The page must carry one mount point for the browser component.');
        self::assertSame(self::TOKENIZATION_KEY, $mount->attr('data-nmi-tokenization-key'));
        self::assertSame('1299', $mount->attr('data-nmi-amount'));
        self::assertSame('USD', $mount->attr('data-nmi-currency'));

        // The private key must never reach the browser.
        self::assertStringNotContainsString(self::SECURITY_KEY, (string) $this->client->getResponse()->getContent());
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

        self::assertCount(1, $crawler->filter('#nmi-three-d-secure'), 'The challenge needs somewhere to render.');

        $mount = $crawler->filter('#nmi-payment');
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
        self::assertStringContainsString('Pagar con tarjeta', $spanish->filter('h1')->text());

        // The message the component shows in place when it cannot read the card is rendered by
        // the template, not by the JavaScript, which is the only reason it can be translated.
        self::assertSame(
            'No se han podido leer los datos de la tarjeta. Revísalos e inténtalo de nuevo.',
            $spanish->filter('#nmi-payment')->attr('data-nmi-error-message'),
        );
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

    /** Enables one more locale on the channel this request's order belongs to. */
    private function alsoSpeaks(PaymentRequestInterface $paymentRequest, string $code): void
    {
        $locale = $this->manager->getRepository(Locale::class)->findOneBy(['code' => $code]) ?? new Locale();
        $locale->setCode($code);
        $this->manager->persist($locale);

        /** @var OrderInterface $order */
        $order = $paymentRequest->getPayment()->getOrder();
        $order->getChannel()?->addLocale($locale);

        $this->manager->flush();
    }
}
