<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Gateway;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;

/**
 * Two NMI accounts, one per channel.
 *
 * Credentials live on the payment method, so this ought to follow — but "ought to follow" is how a
 * store ends up charging one brand's card against another brand's merchant account, and nothing in
 * the store would show it. The only place the answer is visible is the credentials each call to the
 * gateway was made with, so that is what this reads.
 */
final class NmiTwoAccountsTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    private FakeNmiClient $gateway;

    private PaymentRequestAnnouncerInterface $announcer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $manager */
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;

        $this->gateway = new FakeNmiClient();
        $container->set('jpm_martin_sylius_nmi.gateway.client', $this->gateway);

        /** @var PaymentRequestAnnouncerInterface $announcer */
        $announcer = $container->get('test.sylius.announcer.payment_request');
        $this->announcer = $announcer;

        $this->manager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->manager->rollback();

        parent::tearDown();
    }

    public function testEachChannelIsChargedAgainstItsOwnAccount(): void
    {
        $this->gateway->willApprove();

        $boutique = $this->aChannelPayingWith('boutique', 'tok-boutique', 'sec-boutique');
        $outlet = $this->aChannelPayingWith('outlet', 'tok-outlet', 'sec-outlet');

        $this->pay($boutique);
        $this->pay($outlet);

        $used = array_map(
            static fn (\JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration $c): array => [$c->tokenizationKey, $c->securityKey],
            $this->gateway->configurations,
        );

        self::assertSame([
            ['tok-boutique', 'sec-boutique'],
            ['tok-outlet', 'sec-outlet'],
        ], $used, 'Each order must reach the account its own channel was configured with.');
    }

    /** And the public half of it, which the shopper's browser is handed. */
    public function testEachChannelsPayPageCarriesItsOwnTokenisationKey(): void
    {
        $boutique = $this->aChannelPayingWith('boutique', 'tok-boutique', 'sec-boutique');
        $outlet = $this->aChannelPayingWith('outlet', 'tok-outlet', 'sec-outlet');

        $this->announcer->dispatchPaymentRequestCommand($boutique);
        $this->announcer->dispatchPaymentRequestCommand($outlet);

        self::assertSame('tok-boutique', $boutique->getResponseData()['tokenization_key'] ?? null);
        self::assertSame('tok-outlet', $outlet->getResponseData()['tokenization_key'] ?? null);
    }

    private function pay(PaymentRequestInterface $paymentRequest): void
    {
        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);
        $paymentRequest->setPayload(['payment_token' => '00000000-000000-000000-000000000000']);
        $this->announcer->dispatchPaymentRequestCommand($paymentRequest);
        $this->manager->flush();
    }

    private function aChannelPayingWith(string $name, string $tokenizationKey, string $securityKey): PaymentRequestInterface
    {
        $suffix = $name . '_' . bin2hex(random_bytes(3));

        $currency = $this->manager->getRepository(Currency::class)->findOneBy(['code' => 'USD']) ?? new Currency();
        $currency->setCode('USD');
        $this->manager->persist($currency);

        $locale = $this->manager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']) ?? new Locale();
        $locale->setCode('en_US');
        $this->manager->persist($locale);

        $channel = new Channel();
        $channel->setCode($suffix);
        $channel->setName($name);
        $channel->setHostname($name . '.localhost');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->addCurrency($currency);
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $this->manager->persist($channel);

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => $tokenizationKey,
            NmiGatewayFactory::CONFIG_SECURITY_KEY => $securityKey,
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE => false,
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('nmi_' . $suffix);
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
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $this->manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setCurrencyCode('USD');
        $payment->setAmount(1299);
        $payment->setMethod($paymentMethod);
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);
        $this->manager->persist($payment);

        $paymentRequest = new PaymentRequest($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $this->manager->persist($paymentRequest);
        $this->manager->flush();

        return $paymentRequest;
    }
}
