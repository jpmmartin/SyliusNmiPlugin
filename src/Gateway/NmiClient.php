<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiDeclinedException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;
use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiTransportException;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
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

    /** @param array<string, mixed>|null $body */
    private function send(NmiGatewayConfiguration $configuration, string $method, string $path, ?array $body): NmiResponse
    {
        $request = $this->requestFactory
            ->createRequest($method, rtrim($configuration->apiBaseUrl, '/') . self::PAYMENTS_PATH . $path)
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

        return $this->interpret($response);
    }

    private function interpret(ResponseInterface $response): NmiResponse
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if (200 !== $status) {
            // A status the gateway uses to describe our request is the merchant's problem; a
            // status that describes the gateway's own condition leaves the outcome unknown,
            // and an unknown outcome must never be reported as a refusal.
            if ($status >= 500 || 429 === $status) {
                throw NmiTransportException::fromInconclusiveStatus($status);
            }

            $error = NmiErrorResponse::fromBody($status, $body);

            throw null === $error
                ? NmiGatewayException::fromHttpStatus($status)
                : NmiGatewayException::fromError($error);
        }

        $parsed = NmiResponse::fromBody($body);

        return match ($parsed->result) {
            NmiResponse::RESULT_APPROVED => $parsed,
            NmiResponse::RESULT_DECLINED => throw new NmiDeclinedException($parsed),
            default => throw NmiGatewayException::fromResponse($parsed),
        };
    }
}
