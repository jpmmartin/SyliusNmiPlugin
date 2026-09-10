<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Entity\NmiStoredCardInterface;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiCardDetails;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\StoredCard;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use JpmMartin\SyliusNmiPlugin\Provider\NmiCardSavingCustomerProviderInterface;
use JpmMartin\SyliusNmiPlugin\Provider\NmiStoredCardOfferInterface;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiStoredCardRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use JpmMartin\SyliusNmiPlugin\Repository\NmiStoredCardRepositoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Second phase: charge the token the browser sent back. This is the only place money moves on
 * the way in, and it happens exactly once per payment request — the platform's duplicate
 * suppression keeps a second request for the same action off this path.
 *
 * Three outcomes, and the difference between them is what the shopper is told and what happens
 * to the order:
 *
 * - approved: the payment is completed or authorised, and the transaction is recorded
 * - declined: the request fails carrying the issuer's wording; **the payment is left alone**, so
 *   the order stays payable and the shopper can try another card
 * - no answer: the request fails and the payment is left alone, because a request that never
 *   came back may still have been taken — and reporting that as a failure the shopper can retry
 *   is the lesser of the two wrongs, while reporting it as paid is the unrecoverable one
 */
final class CompleteCardPaymentHandler
{
    /** A saved card that cannot be charged: gone, expired, or never this shopper's to begin with. */
    public const CARD_UNAVAILABLE_MESSAGE_KEY = 'jpm_martin_sylius_nmi.payment.card_unavailable';

    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly StateMachineInterface $stateMachine,
        private readonly NmiCardSavingCustomerProviderInterface $cardSavingCustomerProvider,
        private readonly NmiStoredCardRecorderInterface $storedCardRecorder,
        private readonly NmiStoredCardRepositoryInterface $storedCardRepository,
        private readonly NmiStoredCardOfferInterface $storedCardOffer,
    ) {
    }

    public function __invoke(CompleteCardPayment $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            // The plugin needs the order behind the payment and the details column on it, both of
            // which only the core model has. A store that replaced it has replaced too much.
            throw new \LogicException(sprintf('Expected a core payment, got "%s".', $payment::class));
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($paymentRequest->getPayload()) ? $paymentRequest->getPayload() : [];

        $token = $this->stringOrNull($payload['payment_token'] ?? null);
        $storedCardId = $this->stringOrNull($payload['stored_card'] ?? null);

        if (null === $token && null === $storedCardId) {
            // Not a submission: the pay page announces this command on *every* view once the
            // request is in progress, so a shopper who simply reloads arrives here with nothing.
            // Leaving the request untouched lets the page render the form again. Failing it would
            // destroy a payment because someone pressed refresh.
            return;
        }

        $authorizing = PaymentRequestInterface::ACTION_AUTHORIZE === $paymentRequest->getAction();
        $savingFor = null;
        $alreadySaved = false;

        try {
            $configuration = $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod());

            if (null !== $storedCardId) {
                $storedCard = $this->storedCardOffer->chosenFor($payment, $configuration, $storedCardId);

                if (null === $storedCard) {
                    // The card cannot be charged and the gateway was never asked. Deleted between
                    // the page and the post, expired, somebody else's, or a bare identifier
                    // somebody typed — all of them mean the same thing to the shopper, and
                    // distinguishing them out loud would only tell an attacker which one it was.
                    $this->fail($paymentRequest, self::CARD_UNAVAILABLE_MESSAGE_KEY, sprintf('Stored card "%s" is not chargeable on this payment.', $storedCardId));

                    return;
                }

                $charge = $this->chargeFromStoredCard($payment, $storedCard, $payload);
            } else {
                // Asked once, here, and used twice: it decides whether the charge asks the gateway
                // to keep the card and, if it does, who the card ends up filed against. Inside the
                // try because it needs the configuration, and resolving that can fail like
                // anything else.
                $savingFor = $this->customerSavingTheCard($payment, $payload, $configuration);

                // **Before the charge, which is the only moment it can be.** Once the sale has run
                // with `add_to_vault` the gateway has already made a second vault record — it
                // deduplicates nothing — and there would be no undoing it from here.
                $alreadySaved = null !== $savingFor && $this->alreadyOnFile($savingFor, $paymentRequest->getMethod(), $payload);

                // Non-null here by the guard above: the request was refused when neither a token
                // nor a saved card arrived, and a saved card is what this branch does not have.
                $charge = $this->chargeFrom($payment, (string) $token, $payload, null !== $savingFor && !$alreadySaved);
            }

            $response = $authorizing
                ? $this->client->authorize($configuration, $charge)
                : $this->client->sale($configuration, $charge);
        } catch (NmiDeclinedException $exception) {
            // The gateway reached a decision and gave the transaction an identifier, so the
            // attempt is recorded: a later notification about it has to resolve to this payment.
            $this->recorder->record($payment, $exception->getResponse(), $this->typeFor($authorizing));

            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.declined', $exception->getDeclineReason());

            return;
        } catch (NmiGatewayException $exception) {
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.failed', $exception->getGatewayMessage() ?? $exception->getMessage());

            return;
        } catch (NmiTransportException $exception) {
            // Nothing is recorded and nothing is completed: the outcome is genuinely unknown.
            $this->fail($paymentRequest, 'jpm_martin_sylius_nmi.payment.unreachable', $exception->getMessage());

            return;
        }

        $this->recorder->record($payment, $response, $this->typeFor($authorizing));

        // **After the approval, and only after it.** A declined card never reaches this line,
        // because the decline was caught above and returned — which is the whole of how "a
        // declined payment leaves no stored card" is kept true.
        $method = $paymentRequest->getMethod();
        if (null !== $savingFor && !$alreadySaved && $method instanceof PaymentMethodInterface) {
            $this->storedCardRecorder->record($savingFor, $method, $response);
        }

        $this->stateMachine->apply(
            $payment,
            PaymentTransitions::GRAPH,
            $authorizing ? PaymentTransitions::TRANSITION_AUTHORIZE : PaymentTransitions::TRANSITION_COMPLETE,
        );

        $paymentRequest->setResponseData($this->responseDataFrom($response) + ($alreadySaved ? ['card_already_saved' => true] : []));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    /** @param array<string, mixed> $payload */
    private function chargeFrom(PaymentInterface $payment, string $token, array $payload, bool $storeCard): Charge
    {
        $order = $payment->getOrder();

        return new Charge(
            paymentToken: $token,
            amount: (int) $payment->getAmount(),
            currencyCode: (string) $payment->getCurrencyCode(),
            // Sent on every charge so a merchant can find the transaction in the gateway's own
            // portal after a lost response. It is not a reconciliation mechanism: nothing in the
            // API looks a payment up by it.
            orderId: $order?->getTokenValue(),
            ipAddress: $this->stringOrNull($payload['ip_address'] ?? null),
            threeDSecure: $this->threeDSecureFrom($payload),
            storeCard: $storeCard,
        );
    }

    /**
     * The same charge, paid for by a card the gateway already holds.
     *
     * Everything about the amount, the order and the authentication is identical — which is the
     * whole of *a stored card charges like a fresh one*. What differs is where the money comes
     * from, and that is one object further down.
     *
     * @param array<string, mixed> $payload
     */
    private function chargeFromStoredCard(PaymentInterface $payment, NmiStoredCardInterface $card, array $payload): Charge
    {
        $order = $payment->getOrder();

        return new Charge(
            paymentToken: null,
            amount: (int) $payment->getAmount(),
            currencyCode: (string) $payment->getCurrencyCode(),
            orderId: $order?->getTokenValue(),
            ipAddress: $this->stringOrNull($payload['ip_address'] ?? null),
            threeDSecure: $this->threeDSecureFrom($payload),
            storedCard: new StoredCard(
                vaultId: (string) $card->getVaultId(),
                billingId: $card->getBillingId(),
                // Cited when the card has one to cite. A card added from the account area was
                // never charged, so it has none, and the gateway takes the sale regardless.
                initialTransactionId: $card->getVaultingTransactionId(),
            ),
        );
    }

    /**
     * Whether this shopper already has this card on file for this payment method.
     *
     * The browser is what makes the check possible in time: Collect.js hands the token over with
     * the masked number, the expiry and the brand, so the same three values the row is keyed on
     * are known before anything is charged. **Verified in Collect.js's documented callback
     * response** — it carries a `card` whose every field may be null — not assumed.
     *
     * Optional there, and therefore optional here: a lookup that did not happen means no check
     * happens now, and the recorder's own guard is what still keeps a second row from appearing.
     * Trusting the browser for these three values is safe because this is a convenience, not a
     * constraint — the worst a tampered one achieves is a card of its own that is not saved.
     *
     * @param array<string, mixed> $payload
     */
    private function alreadyOnFile(CustomerInterface $customer, mixed $paymentMethod, array $payload): bool
    {
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            return false;
        }

        $card = NmiCardDetails::fromBrowserReport(
            $payload['store_card_brand'] ?? null,
            $payload['store_card_last_four'] ?? null,
            $payload['store_card_exp'] ?? null,
        );

        if (null === $card) {
            return false;
        }

        return null !== $this->storedCardRepository->findOneDuplicate(
            $customer,
            $paymentMethod,
            $card->brand,
            $card->lastFour,
            $card->expiryMonth,
            $card->expiryYear,
        );
    }

    /**
     * The browser asked; this decides, and answers with who the card would belong to.
     *
     * Both halves are required. What was posted is a request; the eligibility check is the
     * permission. A guest who posts the flag anyway — by hand, or because the page was left open
     * after signing out — gets a charge with no vault instruction on it at all, which is the only
     * place that guarantee can actually be made.
     *
     * @param array<string, mixed> $payload
     */
    private function customerSavingTheCard(PaymentInterface $payment, array $payload, NmiGatewayConfiguration $configuration): ?CustomerInterface
    {
        if (null === $this->stringOrNull($payload['store_card'] ?? null)) {
            return null;
        }

        return $this->cardSavingCustomerProvider->forPayment($payment, $configuration);
    }

    /** @param array<string, mixed> $payload */
    private function threeDSecureFrom(array $payload): ?ThreeDSecureResult
    {
        $result = new ThreeDSecureResult(
            status: $this->stringOrNull($payload['cardholder_auth'] ?? null),
            cavv: $this->stringOrNull($payload['cavv'] ?? null),
            xid: $this->stringOrNull($payload['xid'] ?? null),
            eci: $this->stringOrNull($payload['eci'] ?? null),
            threeDsVersion: $this->stringOrNull($payload['three_ds_version'] ?? null),
            directoryServerId: $this->stringOrNull($payload['directory_server_id'] ?? null),
        );

        // The gateway rejects a body carrying fields it does not expect, so an empty
        // authentication object is omitted rather than sent.
        return [] === $result->toArray() ? null : $result;
    }

    /** @return array<string, mixed> */
    private function responseDataFrom(NmiResponse $response): array
    {
        return [
            'transaction_id' => $response->transactionId,
            'status' => $response->status,
            'response_code' => $response->responseCode,
            'response_text' => $response->responseText,
            'auth_code' => $response->authCode,
        ];
    }

    private function typeFor(bool $authorizing): string
    {
        return $authorizing ? NmiTransactionInterface::TYPE_AUTH : NmiTransactionInterface::TYPE_SALE;
    }

    /**
     * Fails the request and records why. The payment itself is untouched, which is what keeps the
     * order payable: a failed request is final, so the shopper's next attempt mints a fresh one.
     */
    private function fail(PaymentRequestInterface $paymentRequest, string $messageKey, string $detail): void
    {
        $paymentRequest->setResponseData([
            'message_key' => $messageKey,
            'detail' => $detail,
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_FAIL,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
