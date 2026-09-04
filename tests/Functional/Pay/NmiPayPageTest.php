<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
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

    /** A finished request has nothing left to collect, so the platform sends the shopper onward. */
    public function testAFinishedRequestIsNotGivenACardForm(): void
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED);

        $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));

        self::assertResponseRedirects();
    }

    protected function paymentRequestManager(): EntityManagerInterface
    {
        return $this->manager;
    }
}
