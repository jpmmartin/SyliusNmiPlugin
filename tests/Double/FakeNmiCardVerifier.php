<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Double;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiCardVerifierInterface;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiGatewayConfiguration;
use JpmMartin\SyliusNmiPlugin\Gateway\NmiResponse;
use JpmMartin\SyliusNmiPlugin\Gateway\Request\CardVerification;

/**
 * The verification that puts a card on file, answered without the gateway.
 *
 * The default answer is **the reply the sandbox gave on 2026-09-21**, abridged to the fields the
 * plugin reads, with one value changed and said so: the expiry. The published test token resolves
 * to a card that expired in October 2025, and a card on file built from that reply would make every
 * later charge in the suite a test of the expiry refusal. The absence of a billing identifier is
 * real — the verification reports none — and must not be "fixed" by adding one.
 */
final class FakeNmiCardVerifier implements NmiCardVerifierInterface
{
    public const TRANSACTION_ID = '12584742193';

    public const VAULT_ID = '1256465022';

    public ?CardVerification $lastVerification = null;

    /** @var list<NmiGatewayConfiguration> */
    public array $configurations = [];

    private ?\Throwable $failure = null;

    private ?NmiResponse $response = null;

    public function willVerify(
        string $transactionId = self::TRANSACTION_ID,
        string $vaultId = self::VAULT_ID,
        string $expiry = '1031',
        string $brand = 'Visa',
        string $lastFour = '1111',
    ): void {
        $this->response = NmiResponse::fromBody(json_encode([
            'object' => 'transaction',
            'id' => $transactionId,
            'type' => 'cc',
            'amount' => '0.00',
            'currency' => 'USD',
            'auth_code' => '',
            'customer_vault_id' => $vaultId,
            'status' => 'complete',
            'response' => '1',
            'response_text' => 'SUCCESS',
            'response_code' => '100',
            'payment_details' => [
                'card_number' => '411111******' . $lastFour,
                'card_exp' => $expiry,
                'card_type' => $brand,
                'card_bin' => '411111',
            ],
            'actions' => [['type' => 'validate', 'amount' => '0.00', 'success' => true, 'response' => '1', 'response_code' => '100']],
        ], \JSON_THROW_ON_ERROR));
        $this->failure = null;
    }

    public function willFail(\Throwable $failure): void
    {
        $this->failure = $failure;
        $this->response = null;
    }

    public function verifyAndStore(NmiGatewayConfiguration $configuration, CardVerification $card): NmiResponse
    {
        $this->lastVerification = $card;
        $this->configurations[] = $configuration;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        if (null === $this->response) {
            $this->willVerify();
        }

        /** @var NmiResponse $response */
        $response = $this->response;

        return $response;
    }

    /** How many times the gateway was asked to put a card on file. */
    public function verificationCount(): int
    {
        return count($this->configurations);
    }
}
