<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Functional\Pay;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use Sylius\Component\Core\Model\Address;
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

        // What the admin form sets for any factory Payum does not know, and this one it does
        // not. Left at its default of true, Sylius classes the method as a Payum gateway and
        // stores its credentials unencrypted — so a fixture that omits this is not testing
        // the configuration a store actually has.
        $gatewayConfig->setUsePayum(false);

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

        // A checked-out order has a billing address, and 3-D Secure needs the name on it.
        $billingAddress = new Address();
        $billingAddress->setFirstName('Ada');
        $billingAddress->setLastName('Lovelace');
        $billingAddress->setStreet('12 Marylebone Rd');
        $billingAddress->setCity('London');
        $billingAddress->setPostcode('NW1 5JR');
        $billingAddress->setCountryCode('GB');
        $manager->persist($billingAddress);

        $order = new Order();
        $order->setBillingAddress($billingAddress);
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        // An order reaching the pay page has been through checkout, so its payment state is
        // already awaiting payment. Leaving it in `cart` would silently defeat the resolver that
        // moves an order to paid or authorized: neither transition starts there.
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $manager->persist($order);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setCurrencyCode('USD');
        $payment->setAmount(self::AMOUNT);
        $payment->setMethod($paymentMethod);
        // A payment starts life in `cart`; reaching the pay page means checkout has moved it on,
        // and the transitions this plugin applies all start from `new`.
        $payment->setState(PaymentInterface::STATE_NEW);
        // Both sides of the relation: Sylius's order-payment resolver reads the order's own
        // collection, and treats an order with no payments as one to mark paid.
        $order->addPayment($payment);
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
