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
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * The smallest store in which a payment request can exist: a channel with a currency and a
 * locale, an NMI payment method, an order and its payment.
 */
trait BuildsAnNmiPaymentRequest
{
    private function newPaymentRequest(
        string $state = PaymentRequestInterface::STATE_NEW,
        string $action = PaymentRequestInterface::ACTION_CAPTURE,
        bool $useAuthorize = false,
    ): PaymentRequest {
        $manager = $this->paymentRequestManager();

        $currency = $manager->getRepository(Currency::class)->findOneBy(['code' => 'USD']) ?? new Currency();
        $currency->setCode('USD');
        $manager->persist($currency);

        $locale = $manager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']) ?? new Locale();
        $locale->setCode('en_US');
        $manager->persist($locale);

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
        $manager->persist($channel);

        // Never `new GatewayConfig()`: registering SyliusPayumBundle swaps the concrete class,
        // so the resource factory is the only thing that knows which one this store uses.
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = self::getContainer()->get('sylius.factory.gateway_config')->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => self::TOKENIZATION_KEY,
            NmiGatewayFactory::CONFIG_SECURITY_KEY => self::SECURITY_KEY,
            NmiGatewayFactory::CONFIG_ENVIRONMENT => NmiGatewayFactory::ENVIRONMENT_SANDBOX,
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE => $useAuthorize,
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('nmi_card_' . bin2hex(random_bytes(4)));
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Card');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);
        $paymentMethod->addChannel($channel);
        $manager->persist($gatewayConfig);
        $manager->persist($paymentMethod);

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setCurrencyCode('USD');
        $payment->setAmount(self::AMOUNT);
        $payment->setMethod($paymentMethod);
        // A payment starts life in `cart`; reaching the pay page means checkout has moved it on,
        // and the transitions this plugin applies all start from `new`.
        $payment->setState(PaymentInterface::STATE_NEW);
        $manager->persist($payment);

        $paymentRequest = new PaymentRequest($payment, $paymentMethod);
        $paymentRequest->setAction($action);
        $paymentRequest->setState($state);
        $manager->persist($paymentRequest);

        $manager->flush();

        return $paymentRequest;
    }

    abstract protected function paymentRequestManager(): EntityManagerInterface;
}
