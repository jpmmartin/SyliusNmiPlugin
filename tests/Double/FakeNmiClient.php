<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;

/**
 * Stands in for the gateway in the automated suite.
 *
 * The client is replaced, not the HTTP layer: a decline or an unreachable gateway is then one
 * line of setup instead of a hand-built response body, and the tests say what they are about.
 * The real client is exercised against the sandbox separately — a fake proves the store's
 * behaviour, never the gateway's.
 */
final class FakeNmiClient implements NmiClientInterface
{
    public ?Charge $lastCharge = null;

    public ?string $lastOperation = null;

    private ?\Throwable $failure = null;

    /** @var array<string, \Throwable> keyed by operation, for the void-or-refund resolution */
    private array $failuresByOperation = [];

    /** @var list<string> every operation asked for, in order */
    public array $operations = [];

    private ?NmiResponse $response = null;

    public function willApprove(string $transactionId = '12513506464', string $amount = '12.99'): void
    {
        $this->response = NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'type' => 'cc',
            'amount' => $amount,
            'currency' => 'USD',
            'auth_code' => '123456',
            'status' => 'pendingsettlement',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
        ], \JSON_THROW_ON_ERROR));
        $this->failure = null;
    }

    public function willFail(\Throwable $failure): void
    {
        $this->failure = $failure;
        $this->response = null;
    }

    /**
     * Fails one operation and leaves the rest working, which is what a settled transaction looks
     * like from the outside: the void is refused and the refund is not.
     */
    public function willFailOn(string $operation, \Throwable $failure): void
    {
        $this->failuresByOperation[$operation] = $failure;
    }

    public function sale(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse
    {
        return $this->answer('sale', $charge);
    }

    public function authorize(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse
    {
        return $this->answer('authorize', $charge);
    }

    public function capture(NmiGatewayConfiguration $configuration, string $transactionId, int $amount, string $currencyCode): NmiResponse
    {
        return $this->answer('capture', null);
    }

    public function void(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse
    {
        return $this->answer('void', null);
    }

    public function refund(NmiGatewayConfiguration $configuration, string $transactionId, ?int $amount, string $currencyCode): NmiResponse
    {
        return $this->answer('refund', null);
    }

    public function retrieve(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse
    {
        return $this->answer('retrieve', null);
    }

    private function answer(string $operation, ?Charge $charge): NmiResponse
    {
        $this->lastOperation = $operation;
        $this->lastCharge = $charge;
        $this->operations[] = $operation;

        if (isset($this->failuresByOperation[$operation])) {
            throw $this->failuresByOperation[$operation];
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->response ?? throw new \LogicException('No gateway answer was prepared.');
    }
}
