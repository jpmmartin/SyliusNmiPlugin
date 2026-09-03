<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;

/**
 * First phase. Writes what the browser needs in order to collect a card and moves the request
 * to processing. **The gateway is not called here** — nothing has been charged, and nothing can
 * be until the browser sends a token back.
 *
 * Everything the browser needs travels in one response, so a client driving the payment through
 * the shop API never has to ask a second time before tokenising a card.
 */
final class PrepareCardPaymentHandler
{
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(PrepareCardPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = $paymentRequest->getPayment();

        // Reads the credentials from this payment method, so two channels on two NMI accounts
        // each hand the browser their own key.
        $configuration = $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod());

        $paymentRequest->setResponseData([
            // Public by design: it identifies the merchant to the browser component and cannot
            // charge anything. The security key never leaves the server.
            'tokenization_key' => $configuration->tokenizationKey,
            'amount' => $payment->getAmount(),
            'currency_code' => $payment->getCurrencyCode(),
            // Which of the two ways this payment is taken was decided upstream from the payment
            // method's configuration; the browser is told so it can label its own button.
            'action' => $paymentRequest->getAction(),
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_PROCESS,
        );
    }
}
