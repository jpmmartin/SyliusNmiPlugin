<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayFactory;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusNmiPlugin\Double\FakeNmiClient;
use Tests\JpmMartin\SyliusNmiPlugin\Double\QueuedWorkCollector;
use Tests\JpmMartin\SyliusNmiPlugin\Support\NmiHost;

/**
 * The store an authorised-order scenario needs: a payment method that authorises first, an order
 * whose authorisation the gateway approved, and what the gateway will say when it is voided.
 *
 * The authorisation is written straight to the database rather than taken through the storefront,
 * because taking it means tokenising a card in the gateway's own frames — which no scenario in
 * continuous integration can do. That half is covered by the functional suite. What is left here is
 * the operator's half, and it runs through the real order page, controller and listeners.
 */
final class NmiAuthorizedOrderContext implements Context
{
    /**
     * @param FactoryInterface<PaymentMethodInterface> $paymentMethodFactory
     * @param FactoryInterface<GatewayConfigInterface> $gatewayConfigFactory
     */
    public function __construct(
        private readonly SharedStorageInterface $sharedStorage,
        private readonly FactoryInterface $paymentMethodFactory,
        private readonly FactoryInterface $gatewayConfigFactory,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly StateMachineInterface $stateMachine,
        private readonly ObjectManager $manager,
    ) {
    }

    /**
     * @BeforeScenario
     */
    public function startKeepingWhatIsQueued(): void
    {
        FakeNmiClient::forgetWhatEveryInstanceWasTold();
        QueuedWorkCollector::start();
    }

    /**
     * @AfterScenario
     */
    public function stopKeepingWhatIsQueued(): void
    {
        FakeNmiClient::forgetWhatEveryInstanceWasTold();
        QueuedWorkCollector::stop();
    }

    /**
     * @Given the store has an NMI payment method :name with a code :code that authorizes first
     */
    public function theStoreHasAnNmiPaymentMethodThatAuthorizesFirst(string $name, string $code): void
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
            NmiGatewayFactory::CONFIG_USE_AUTHORIZE => true,
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
     * As checkout leaves an order on a method that authorises first: the authorisation approved and
     * on record, the payment authorized, nothing captured.
     *
     * @Given /^(this order) is authorized by NMI as "(\d+)"$/
     */
    public function thisOrderIsAuthorizedByNmi(OrderInterface $order, string $authorization): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        if (null === $payment) {
            throw new \RuntimeException('The order has no payment waiting to be taken.');
        }

        $this->recorder->record($payment, NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $authorization,
            'type' => 'cc',
            'currency' => 'USD',
            'status' => 'pending',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
            'auth_code' => '123456',
        ], \JSON_THROW_ON_ERROR)), NmiTransactionInterface::TYPE_AUTH);

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_AUTHORIZE);

        $this->manager->flush();
    }

    /**
     * A void keeps its authorisation's identifier, so this is what the gateway answers with.
     *
     * @Given NMI will approve the void of :authorization
     */
    public function nmiWillApproveTheVoidOf(string $authorization): void
    {
        FakeNmiClient::everyInstanceWillApprove($authorization);
    }
}
