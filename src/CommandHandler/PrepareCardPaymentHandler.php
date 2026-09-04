<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
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
        private readonly NmiAmountFormatter $amountFormatter,
    ) {
    }

    public function __invoke(PrepareCardPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = $paymentRequest->getPayment();

        // Reads the credentials from this payment method, so two channels on two NMI accounts
        // each hand the browser their own key.
        $configuration = $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod());

        $currencyCode = (string) $payment->getCurrencyCode();
        $amount = (int) $payment->getAmount();

        $paymentRequest->setResponseData([
            // Public by design: it identifies the merchant to the browser component and cannot
            // charge anything. The security key never leaves the server.
            'tokenization_key' => $configuration->tokenizationKey,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            // The same amount in the decimal form the browser's authentication call wants. It is
            // derived here rather than in the browser because how many decimals a currency has is
            // not something JavaScript should be deciding.
            'amount_major' => $this->amountFormatter->format($amount, $currencyCode),
            // Which of the two ways this payment is taken was decided upstream from the payment
            // method's configuration; the browser is told so it can label its own button.
            'action' => $paymentRequest->getAction(),
        ] + $this->cardholderFrom($payment));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_PROCESS,
        );
    }

    /**
     * Who the card belongs to, for the browser's authentication call. The issuer decides whether
     * to challenge a shopper from what it is told about them, and the gateway is explicit that the
     * more it receives the less likely a challenge is — so the billing address travels too, not
     * just the name the call demands.
     *
     * @return array<string, string>
     */
    private function cardholderFrom(mixed $payment): array
    {
        if (!$payment instanceof PaymentInterface) {
            return [];
        }

        $order = $payment->getOrder();
        $address = $order?->getBillingAddress();

        return array_filter([
            'first_name' => $address?->getFirstName(),
            'last_name' => $address?->getLastName(),
            'email' => $order?->getCustomer()?->getEmail(),
            'address1' => $address?->getStreet(),
            'city' => $address?->getCity(),
            'postal_code' => $address?->getPostcode(),
            'state' => $address?->getProvinceCode(),
            'country' => $address?->getCountryCode(),
            'phone' => $address?->getPhoneNumber(),
        ], static fn (?string $value): bool => null !== $value && '' !== trim($value));
    }
}
