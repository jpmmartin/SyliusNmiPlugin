<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiClientInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiVaultRecord;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\Charge;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\VaultCard;

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

    private ?NmiVaultRecord $vaultRecord = null;

    /** @var list<string> every vault id the caller asked to forget */
    public array $deletedVaultIds = [];

    public ?VaultCard $lastVaultCard = null;

    /**
     * What the gateway answers when it keeps a card: a customer, not a transaction.
     *
     * **Copied from a real reply, and the absence of `card_type` is the point.** This fixture used
     * to name the brand, and nothing did: creating a vault record answers with `card_number` and
     * `card_exp` and nothing else, while the charge that stores a card *does* return the brand. The
     * invented key made the add-a-card path pass a test it would have failed against the gateway,
     * where the card could not be described and the record was left stranded. Do not add it back.
     */
    private const VAULT_RECORD = '{"object":"customer","id":"1732163788","created":"2026-09-08T18:26:24+00:00","billing":[{"object":"billing","id":"1620589323","first_name":"Ada","last_name":"Lovelace","address1":"12 Marylebone Rd","city":"London","zip":"NW1 5JR","country":"GB","priority":1,"payment_details":{"card_number":"411111******1111","card_exp":"1030"}}],"shipping":[],"merchant_defined_fields":{}}';

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

    public function createVaultRecord(NmiGatewayConfiguration $configuration, VaultCard $card): NmiVaultRecord
    {
        $this->lastOperation = 'create_vault_record';
        $this->lastVaultCard = $card;
        $this->operations[] = 'create_vault_record';
        $this->configurations[] = $configuration;

        if (isset($this->failuresByOperation['create_vault_record'])) {
            throw $this->failuresByOperation['create_vault_record'];
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        // The brand travels from the caller, exactly as it does against the real gateway, because
        // this endpoint does not report one.
        return $this->vaultRecord ?? NmiVaultRecord::fromBody(self::VAULT_RECORD, $card->brand);
    }

    public function deleteVaultRecord(NmiGatewayConfiguration $configuration, string $vaultId): void
    {
        $this->lastOperation = 'delete_vault_record';
        $this->deletedVaultIds[] = $vaultId;
        $this->operations[] = 'delete_vault_record';
        $this->configurations[] = $configuration;

        if (isset($this->failuresByOperation['delete_vault_record'])) {
            throw $this->failuresByOperation['delete_vault_record'];
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    public function willVault(NmiVaultRecord $record): void
    {
        $this->vaultRecord = $record;
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
