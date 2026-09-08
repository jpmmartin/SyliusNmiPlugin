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

    /** @var list<NmiGatewayConfiguration> the credentials each operation was asked with, in order */
    public array $configurations = [];

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

    /**
     * An approval that also kept the card: the vault reference sits beside the transaction, and
     * the charge describes the card it took. Both were observed against the sandbox — the gateway
     * returns no billing-shaped key here, which is why the entity's billing id stays null.
     */
    public function willApproveAndKeepTheCard(
        string $vaultId = '1730549219',
        string $transactionId = '12513506464',
        string $brand = 'Visa',
        string $lastFour = '1111',
        string $expiry = '1029',
    ): void {
        $this->willApprove($transactionId);

        /** @var array<string, mixed> $body */
        $body = $this->response?->raw ?? [];
        $body['customer_vault_id'] = $vaultId;
        $body['payment_details'] = [
            // Masked by the gateway, and capitalised by it too — the browser spells the same
            // brand `visa`, which is why nothing compares these two as they arrive.
            'card_number' => '411111******' . $lastFour,
            'card_exp' => $expiry,
            'card_type' => $brand,
            'card_bin' => '411111',
        ];

        $this->response = NmiResponse::fromBody(json_encode($body, \JSON_THROW_ON_ERROR));
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
        return $this->answer('sale', $charge, $configuration);
    }

    public function authorize(NmiGatewayConfiguration $configuration, Charge $charge): NmiResponse
    {
        return $this->answer('authorize', $charge, $configuration);
    }

    public function capture(NmiGatewayConfiguration $configuration, string $transactionId, int $amount, string $currencyCode): NmiResponse
    {
        return $this->answer('capture', null, $configuration);
    }

    public function void(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse
    {
        return $this->answer('void', null, $configuration);
    }

    public function refund(NmiGatewayConfiguration $configuration, string $transactionId, ?int $amount, string $currencyCode): NmiResponse
    {
        return $this->answer('refund', null, $configuration);
    }

    public function retrieve(NmiGatewayConfiguration $configuration, string $transactionId): NmiResponse
    {
        return $this->answer('retrieve', null, $configuration);
    }

    private function answer(string $operation, ?Charge $charge, ?NmiGatewayConfiguration $configuration = null): NmiResponse
    {
        $this->lastOperation = $operation;
        $this->lastCharge = $charge;
        $this->operations[] = $operation;

        // Which credentials the operation was asked with. Two payment methods on two channels must
        // reach two different NMI accounts, and nothing else in the store would show it.
        if (null !== $configuration) {
            $this->configurations[] = $configuration;
        }

        if (isset($this->failuresByOperation[$operation])) {
            throw $this->failuresByOperation[$operation];
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->response ?? throw new \LogicException('No gateway answer was prepared.');
    }
}
