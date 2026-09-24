<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiCardOnFileInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiRecurringCredentialInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * The store a held-order scenario needs: a payment method that takes payment later, an order whose
 * card is on file and whose payment waits, and what the gateway will say when that card is charged.
 *
 * The card is written straight to the database rather than put on file through the storefront,
 * because putting it there means tokenising a card in the gateway's own frames — which no scenario
 * in continuous integration can do. That half is covered by the functional suite. What is left here
 * is the operator's half, and it runs through the real order page, controller and listeners.
 *
 * The gateway's answer is given to every instance of the fake at once: the page's kernel is rebooted
 * between requests, so an answer set on one instance would not reach the charge.
 */
final class NmiHeldOrderContext implements Context
{
    /**
     * @param FactoryInterface<PaymentMethodInterface> $paymentMethodFactory
     * @param FactoryInterface<GatewayConfigInterface> $gatewayConfigFactory
     * @param FactoryInterface<NmiCardOnFileInterface> $cardOnFileFactory
     * @param FactoryInterface<NmiRecurringCredentialInterface> $recurringCredentialFactory
     */
    public function __construct(
        private readonly SharedStorageInterface $sharedStorage,
        private readonly FactoryInterface $paymentMethodFactory,
        private readonly FactoryInterface $gatewayConfigFactory,
        private readonly FactoryInterface $cardOnFileFactory,
        private readonly StateMachineInterface $stateMachine,
        private readonly ObjectManager $manager,
        private readonly FactoryInterface $recurringCredentialFactory,
    ) {
    }

    /**
     * @BeforeScenario
     *
     * @AfterScenario
     */
    public function forgetWhatTheGatewayWasTold(): void
    {
        FakeNmiClient::forgetWhatEveryInstanceWasTold();
    }

    /**
     * @Given the store has an NMI payment method :name with a code :code that takes payment later
     */
    public function theStoreHasAnNmiPaymentMethodThatTakesPaymentLater(string $name, string $code): void
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $this->gatewayConfigFactory->createNew();
        $gatewayConfig->setGatewayName(NmiGatewayFactory::NAME);
        $gatewayConfig->setFactoryName(NmiGatewayFactory::NAME);
        // Without this Sylius treats the method as a Payum gateway and stores its credentials in
        // the clear, which is not the configuration a store actually has.
        $gatewayConfig->setUsePayum(false);
        $gatewayConfig->setConfig([
            NmiGatewayFactory::CONFIG_TOKENIZATION_KEY => 'tok-public-0123',
            NmiGatewayFactory::CONFIG_SECURITY_KEY => 'sec-private-4567',
            NmiGatewayFactory::CONFIG_API_BASE_URL => NmiHost::forTests(),
            NmiGatewayFactory::CONFIG_TAKE_PAYMENT_LATER => true,
        ]);

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->paymentMethodFactory->createNew();
        $paymentMethod->setCode($code);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName($name);
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);

        $channel = $this->sharedStorage->has('channel') ? $this->sharedStorage->get('channel') : null;
        if (null !== $channel) {
            $paymentMethod->addChannel($channel);
        }

        $this->manager->persist($gatewayConfig);
        $this->manager->persist($paymentMethod);
        $this->manager->flush();

        $this->sharedStorage->set('payment_method', $paymentMethod);
    }

    /**
     * As the deferred checkout leaves an order: the card verified and on file, the payment waiting
     * in processing, nothing charged.
     *
     * @Given /^(this order) is held with a "([^"]+)" card ending "(\d{4})" on file$/
     */
    public function thisOrderIsHeldWithACardOnFile(OrderInterface $order, string $brand, string $lastFour): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        if (null === $payment) {
            throw new \RuntimeException('The order has no payment waiting to be taken.');
        }

        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface) {
            throw new \RuntimeException('The order\'s payment has no payment method.');
        }

        /** @var NmiCardOnFileInterface $card */
        $card = $this->cardOnFileFactory->createNew();
        $card->setPayment($payment);
        $card->setPaymentMethod($method);
        // Never asserted on in a scenario: an operator sees the brand and last four, not the
        // gateway's references.
        $card->setVaultId('vault-' . $lastFour);
        $card->setInitialTransactionId('12584742193');
        $card->setBrand($brand);
        $card->setLastFour($lastFour);
        $card->setExpiryMonth(10);
        $card->setExpiryYear(2035);
        $this->manager->persist($card);

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS);

        $this->manager->flush();
    }

    /**
     * As the deferred checkout leaves an order whose payment opened recurring charges: the card
     * verified and kept as a recurring credential rather than on file, the payment waiting in
     * processing, nothing charged.
     *
     * @Given /^(this order) is held with a "([^"]+)" card ending "(\d{4})" kept for renewals$/
     */
    public function thisOrderIsHeldWithACardKeptForRenewals(OrderInterface $order, string $brand, string $lastFour): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        if (null === $payment) {
            throw new \RuntimeException('The order has no payment waiting to be taken.');
        }

        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface) {
            throw new \RuntimeException('The order\'s payment has no payment method.');
        }

        $customer = $order->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            throw new \RuntimeException('The order has no customer.');
        }

        /** @var NmiRecurringCredentialInterface $credential */
        $credential = $this->recurringCredentialFactory->createNew();
        $credential->setInitialPayment($payment);
        $credential->setCustomer($customer);
        $credential->setPaymentMethod($method);
        $credential->setVaultId('vault-' . $lastFour);
        $credential->setInitialTransactionId('12592792407');
        $credential->setBrand($brand);
        $credential->setLastFour($lastFour);
        $credential->setExpiryMonth(10);
        $credential->setExpiryYear(2035);
        $this->manager->persist($credential);

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_PROCESS);

        $this->manager->flush();
    }

    /**
     * @Given NMI will approve the charge
     */
    public function nmiWillApproveTheCharge(): void
    {
        FakeNmiClient::everyInstanceWillApprove('12584746059');
    }

    /**
     * The sentence the gateway returns with the decline, which is what the operator is shown.
     *
     * @Given NMI will decline the charge with :reason
     */
    public function nmiWillDeclineTheChargeWith(string $reason): void
    {
        FakeNmiClient::everyInstanceWillFail(new NmiDeclinedException(NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => '12584700003',
            'type' => 'cc',
            'currency' => 'USD',
            'response' => '2',
            'response_text' => $reason,
            'response_code' => '200',
        ], \JSON_THROW_ON_ERROR))));
    }
}
