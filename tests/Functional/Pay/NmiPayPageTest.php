<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
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
    private const TOKENIZATION_KEY = 'tok-public-0123';

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
        self::assertStringNotContainsString('sec-private-4567', (string) $this->client->getResponse()->getContent());
    }

    /** A finished request has nothing left to collect, so the platform sends the shopper onward. */
    public function testAFinishedRequestIsNotGivenACardForm(): void
    {
        $paymentRequest = $this->newPaymentRequest(PaymentRequestInterface::STATE_COMPLETED);

        $this->client->request('GET', sprintf('/en_US/payment-request/pay/%s', (string) $paymentRequest->getId()));

        self::assertResponseRedirects();
    }

    private function newPaymentRequest(string $state = PaymentRequestInterface::STATE_NEW): PaymentRequest
    {
        $currency = $this->manager->getRepository(Currency::class)->findOneBy(['code' => 'USD']) ?? new Currency();
        $currency->setCode('USD');
        $this->manager->persist($currency);

        $locale = $this->manager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']) ?? new Locale();
        $locale->setCode('en_US');
        $this->manager->persist($locale);

        $channel = new Channel();
        $channel->setCode('nmi_test_' . bin2hex(random_bytes(4)));
        $channel->setName('NMI test channel');
        $channel->setHostname('localhost');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->addCurrency($currency);
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $this->manager->persist($channel);

        // Never `new GatewayConfig()`: registering SyliusPayumBundle swaps the concrete class,
        // so the resource factory is the only thing that knows which one this store uses.
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => self::TOKENIZATION_KEY,
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-private-4567',
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE => false,
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('nmi_card_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);
        $paymentMethod->addChannel($channel);
        $this->manager->persist($gatewayConfig);
        $this->manager->persist($paymentMethod);

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $this->manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setCurrencyCode('USD');
        $payment->setAmount(1299);
        $payment->setMethod($paymentMethod);
        $this->manager->persist($payment);

        $paymentRequest = new PaymentRequest($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $paymentRequest->setState($state);
        $this->manager->persist($paymentRequest);

        $this->manager->flush();

        return $paymentRequest;
    }
}
