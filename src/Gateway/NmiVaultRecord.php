<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;

/**
 * What the gateway answers when asked to keep a card: a customer object, not a transaction.
 *
 * It has no `response` field, no amount and no authorisation, so `NmiResponse` cannot read it —
 * that class would reject this body as malformed, correctly. Adding a card from the account area
 * reports nothing that could appear on a statement, which is what makes it a card-storing call
 * rather than a purchase.
 */
final class NmiVaultRecord
{
    private function __construct(
        public readonly string $vaultId,
        /** The billing record inside the vault entry. Only this endpoint reports it. */
        public readonly ?string $billingId,
        public readonly ?NmiCardDetails $card,
        /** @var array<string, mixed> */
        public readonly array $raw,
    ) {
    }

    /**
     * @param string|null $brandWhenTheGatewayIsSilent what the browser reported, because this
     *                                                 endpoint answers with no brand of its own
     *
     * @throws NmiGatewayException when the body is not a customer
     */
    public static function fromBody(string $body, ?string $brandWhenTheGatewayIsSilent = null): self
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw NmiGatewayException::malformedResponse();
        }

        /** @var array<string, mixed> $raw */
        $raw = $decoded;

        $vaultId = $raw['id'] ?? null;
        if (!is_scalar($vaultId) || '' === (string) $vaultId) {
            throw NmiGatewayException::malformedResponse();
        }

        // The gateway returns billing as a list; a vault entry this plugin creates holds exactly
        // one, because it stores one card per entry and never adds a second address to an entry.
        $billing = [];
        if (isset($raw['billing']) && is_array($raw['billing'])) {
            $first = reset($raw['billing']);
            $billing = is_array($first) ? $first : [];
        }

        $billingId = $billing['id'] ?? null;
        $paymentDetails = $billing['payment_details'] ?? null;

        return new self(
            vaultId: (string) $vaultId,
            billingId: is_scalar($billingId) && '' !== (string) $billingId ? (string) $billingId : null,
            card: is_array($paymentDetails) ? NmiCardDetails::fromPaymentDetails($paymentDetails, $brandWhenTheGatewayIsSilent) : null,
            raw: $raw,
        );
    }
}
