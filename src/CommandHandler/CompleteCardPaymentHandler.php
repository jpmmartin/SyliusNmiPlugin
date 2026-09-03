<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CommandHandler;

use JpmMartin\SyliusNmiPlugin\Command\CompleteCardPayment;
use JpmMartin\SyliusNmiPlugin\Entity\NmiTransactionInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfigurationProviderInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\ThreeDSecureResult;
use JpmMartin\SyliusNmiPlugin\Recorder\NmiTransactionRecorderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
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
    public function __construct(
        private readonly PaymentRequestProviderInterface $paymentRequestProvider,
        private readonly NmiGatewayConfigurationProviderInterface $configurationProvider,
        private readonly NmiClientInterface $client,
        private readonly NmiTransactionRecorderInterface $recorder,
        private readonly StateMachineInterface $stateMachine,
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

        $token = $payload['payment_token'] ?? null;
        if (!is_string($token) || '' === $token) {
            // Not a submission: the pay page announces this command on *every* view once the
            // request is in progress, so a shopper who simply reloads arrives here with nothing.
            // Leaving the request untouched lets the page render the form again. Failing it would
            // destroy a payment because someone pressed refresh.
            return;
        }

        $authorizing = PaymentRequestInterface::ACTION_AUTHORIZE === $paymentRequest->getAction();
        $charge = $this->chargeFrom($payment, $token, $payload);

        try {
            $configuration = $this->configurationProvider->fromPaymentMethod($paymentRequest->getMethod());

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

        $this->stateMachine->apply(
            $payment,
            PaymentTransitions::GRAPH,
            $authorizing ? PaymentTransitions::TRANSITION_AUTHORIZE : PaymentTransitions::TRANSITION_COMPLETE,
        );

        $paymentRequest->setResponseData($this->responseDataFrom($response));

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }

    /** @param array<string, mixed> $payload */
    private function chargeFrom(PaymentInterface $payment, string $token, array $payload): Charge
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
        );
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
