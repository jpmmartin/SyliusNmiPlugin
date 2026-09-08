<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\VaultCard;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The one code path to the gateway, over its REST API.
 *
 * Two things about that API drive the shape of this class. The key is the entire value of the
 * `Authorization` header — a scheme prefix is rejected — and the status code answers a
 * different question from the body: a status of 200 means the gateway reached a decision,
 * which may well be a decline, while everything else means it did not.
 */
final class NmiClient implements NmiClientInterface
{
    public const PAYMENTS_PATH = '/api/v5/payments';

    /** The vault lives on its own resource; the payments endpoint cannot store a card alone. */
    public const CUSTOMERS_PATH = '/api/v5/customers';

    /** Refunding this amount refunds the whole transaction. */
    private const REFUND_EVERYTHING = '0.00';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly NmiAmountFormatter $amountFormatter,
    ) {
    }

    public function sale(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse
    {
        return $this->send($configuration, 'POST', '/sale', $this->chargeBody($charge));
    }

    public function authorize(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse
    {
        return $this->send($configuration, 'POST', '/auth', $this->chargeBody($charge));
    }

    public function capture(NmiGatewayConfiguration $configuration, string $transactionId, int $amount, string $currencyCode): NmiResponse
    {
        return $this->send($configuration, 'POST', sprintf('/%s/capture', rawurlencode($transactionId)), [
            'amount' => $this->amountFormatter->format($amount, $currencyCode),
        ]);
    }

    public function void(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse
    {
        // The gateway requires a body here and accepts an empty object; a JSON array is not one.
        return $this->send($configuration, 'POST', sprintf('/%s/void', rawurlencode($transactionId)), []);
    }

    public function refund(NmiGatewayConfiguration $configuration, string $transactionId, ?int $amount, string $currencyCode): NmiResponse
    {
        return $this->send($configuration, 'POST', sprintf('/%s/refund', rawurlencode($transactionId)), [
            'amount' => null === $amount ? self::REFUND_EVERYTHING : $this->amountFormatter->format($amount, $currencyCode),
        ]);
    }

    public function retrieve(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse
    {
        return $this->send($configuration, 'GET', sprintf('/%s', rawurlencode($transactionId)), null);
    }

    /** @return array<string, mixed> */
    private function chargeBody(Charge $charge): array
    {
        $body = [
            'amount' => $this->amountFormatter->format($charge->amount, $charge->currencyCode),
            'currency' => strtoupper($charge->currencyCode),
            'payment_details' => ['payment_token' => $charge->paymentToken],
        ];

        $orderDetails = array_filter([
            'id' => $charge->orderId,
            'order_description' => $charge->orderDescription,
            'ip_address' => $charge->ipAddress,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);

        if ([] !== $orderDetails) {
            $body['order_details'] = $orderDetails;
        }

        $billing = $charge->billing?->toArray() ?? [];
        if ([] !== $billing) {
            $body['billing_address'] = $billing;
        }

        $threeDSecure = $charge->threeDSecure?->toArray() ?? [];
        if ([] !== $threeDSecure) {
            $body['cardholder_auth'] = $threeDSecure;
        }

        // `add_to_vault`, not the `add_customer` the published examples show: that spelling is
        // refused as an unexpected parameter. Established against the gateway, not read.
        if ($charge->storeCard) {
            $body['customer_vault'] = ['add_to_vault' => true];
        }

        return $body;
    }

    public function createVaultRecord(NmiGatewayConfiguration $configuration, VaultCard $card): NmiVaultRecord
    {
        // The token and the address go *inside* one `billing` object. The published example puts
        // `payment_details`, `cit_mit` and `billing_address` at the top level, and the gateway
        // refuses all three as unexpected parameters — established against it, not read.
        $billing = $card->billing?->toArray() ?? [];
        $billing['payment_details'] = ['payment_token' => $card->paymentToken];

        return NmiVaultRecord::fromBody(
            $this->request($configuration, 'POST', self::CUSTOMERS_PATH, ['billing' => $billing]),
        );
    }

    public function deleteVaultRecord(NmiGatewayConfiguration $configuration, string $vaultId): void
    {
        $this->request($configuration, 'DELETE', self::CUSTOMERS_PATH . '/' . rawurlencode($vaultId), null);
    }

    /**
     * A call to the payments endpoint, whose answer is always a transaction.
     *
     * @param array<string, mixed>|null $body
     */
    private function send(NmiGatewayConfiguration $configuration, string $method, string $path, ?array $body): NmiResponse
    {
        return $this->interpret($this->request($configuration, $method, self::PAYMENTS_PATH . $path, $body));
    }

    /**
     * One call to any of the gateway's resources, returning its body untouched.
     *
     * Split out from `send()` because the vault lives at its own path and answers with its own
     * shape: a customer object rather than a transaction, and nothing at all on a delete. What the
     * two share is how a failure is read, which is why that stays here.
     *
     * @param array<string, mixed>|null $body
     */
    private function request(NmiGatewayConfiguration $configuration, string $method, string $path, ?array $body): string
    {
        $request = $this->requestFactory
            ->createRequest($method, rtrim($configuration->apiBaseUrl, '/') . $path)
            // The private key is the whole header value: a scheme prefix is rejected as missing.
            ->withHeader('Authorization', $configuration->securityKey)
            ->withHeader('Accept', 'application/json')
        ;

        if (null !== $body) {
            $encoded = json_encode($body === [] ? new \stdClass() : $body, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encoded))
            ;
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw NmiTransportException::fromClientException($exception);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        // A successful delete answers 204 with no body at all, which is a success and not a
        // malformed response.
        if (204 === $status) {
            return '';
        }

        if (200 !== $status) {
            // A status the gateway uses to describe our request is the merchant's problem; a
            // status that describes the gateway's own condition leaves the outcome unknown,
            // and an unknown outcome must never be reported as a refusal.
            if ($status >= 500 || 429 === $status) {
                throw NmiTransportException::fromInconclusiveStatus($status);
            }

            $error = NmiErrorResponse::fromBody($status, $raw);

            throw null === $error
                ? NmiGatewayException::fromHttpStatus($status)
                : NmiGatewayException::fromError($error);
        }

        return $raw;
    }

    /** What a transaction body means: approved, declined, or refused. */
    private function interpret(string $body): NmiResponse
    {
        $parsed = NmiResponse::fromBody($body);

        return match ($parsed->result) {
            NmiResponse::RESULT_APPROVED => $parsed,
            NmiResponse::RESULT_DECLINED => throw new NmiDeclinedException($parsed),
            default => throw NmiGatewayException::fromResponse($parsed),
        };
    }
}
