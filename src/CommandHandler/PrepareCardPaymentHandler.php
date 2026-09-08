<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\PrepareCardPayment;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Provider\NmiCardSavingCustomerProviderInterface;
use JpmMartin\SyliusNmiPlugin\Provider\NmiStoredCardOfferInterface;
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
        private readonly NmiCardSavingCustomerProviderInterface $cardSavingCustomerProvider,
        private readonly NmiStoredCardOfferInterface $storedCardOffer,
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
        ] + $this->cardholderFrom($payment) + $this->cardSavingFrom($payment, $configuration) + $this->storedCardsFrom($payment, $configuration));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_PROCESS,
        );
    }

    /**
     * Present only when a card may actually be saved out of this payment, and absent otherwise —
     * which is what keeps a store that never turned the setting on from seeing a key it has no
     * use for, in the storefront and in the API response alike.
     *
     * @return array<string, bool>
     */
    private function cardSavingFrom(mixed $payment, NmiGatewayConfiguration $configuration): array
    {
        if (!$payment instanceof PaymentInterface) {
            return [];
        }

        return null === $this->cardSavingCustomerProvider->forPayment($payment, $configuration)
            ? []
            : ['can_store_card' => true];
    }

    /**
     * The cards this shopper may pay with, in the order they should be shown: the default first,
     * then the rest, newest before oldest.
     *
     * Absent rather than empty when there are none, for the same reason `can_store_card` is: a
     * store that never turned card storage on sees the response it has always seen, and so does
     * the README's captured example.
     *
     * **No vault reference travels.** What identifies a card here is its row, which is meaningless
     * anywhere but this store — the gateway's own reference stays on the server, where the charge
     * reads it back out of the row.
     *
     * @return array<string, bool|list<array<string, bool|int|string|null>>>
     */
    private function storedCardsFrom(mixed $payment, NmiGatewayConfiguration $configuration): array
    {
        if (!$payment instanceof PaymentInterface) {
            return [];
        }

        $cards = array_map(static fn (NmiStoredCardInterface $card): array => [
            'id' => $card->getId(),
            'brand' => $card->getBrand(),
            'last_four' => $card->getLastFour(),
            'expiry_month' => $card->getExpiryMonth(),
            'expiry_year' => $card->getExpiryYear(),
            'is_default' => $card->isDefault(),
            // Listed and marked rather than hidden: a shopper looking for a card they know they
            // saved has to be told why it is not among the choices.
            'is_expired' => $card->isExpired(),
        ], $this->storedCardOffer->offeredFor($payment, $configuration));

        return [] === $cards ? [] : [
            'stored_cards' => $cards,
            // Whether paying with one of them authenticates again. It travels beside the cards
            // rather than on its own so that a store with none of them still sees the response it
            // has always seen.
            'authenticate_stored_cards' => $configuration->authenticateStoredCards,
        ];
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
