<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\CardOnFile;

/**
 * What became of a charge of a card on file, told to the code that asked for it.
 *
 * Four answers and no more, because a caller has to act differently on each:
 *
 * - **approved** — the money was taken; the payment is completed
 * - **declined** — the gateway reached a decision and it was no; the payment still waits, with its
 *   card on file, and the reason is the issuer's or the gateway's own
 * - **refused** — nothing was sent: a condition for charging without the shopper did not hold, and
 *   the reason names which one
 * - **unknown** — the gateway did not answer, so whether the card was charged cannot be known from
 *   here. Never reported as a decline: retrying on the strength of one could charge twice
 */
final class NmiChargeOutcome
{
    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const REFUSED = 'refused';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $status,
        /** A translation key naming what happened, in the `flashes` domain, for an operator. */
        public readonly string $messageKey,
        /** The gateway's or the issuer's own wording when there is one; it is often the only precise description. */
        public readonly ?string $reason = null,
        /** The gateway's identifier for the attempt, when it gave one. */
        public readonly ?string $transactionId = null,
    ) {
    }

    public static function approved(string $transactionId): self
    {
        return new self(self::APPROVED, 'jpm_martin_sylius_nmi.payment.card_on_file_charged', null, $transactionId);
    }

    public static function declined(string $messageKey, ?string $reason, ?string $transactionId = null): self
    {
        return new self(self::DECLINED, $messageKey, $reason, $transactionId);
    }

    public static function refused(string $messageKey, ?string $reason = null): self
    {
        return new self(self::REFUSED, $messageKey, $reason);
    }

    public static function unknown(string $messageKey, ?string $reason = null): self
    {
        return new self(self::UNKNOWN, $messageKey, $reason);
    }

    public function isApproved(): bool
    {
        return self::APPROVED === $this->status;
    }

    /**
     * As a payment request's response data carries it, so that the outcome survives the request and
     * can be read back — by the code that asked, and by an operator reading the request later.
     *
     * @return array{outcome: string, message_key: string, detail: string|null, transaction_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->status,
            'message_key' => $this->messageKey,
            'detail' => $this->reason,
            'transaction_id' => $this->transactionId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $status = $data['outcome'] ?? null;
        $messageKey = $data['message_key'] ?? null;
        if (!in_array($status, [self::APPROVED, self::DECLINED, self::REFUSED, self::UNKNOWN], true) || !is_string($messageKey)) {
            return null;
        }

        $reason = is_string($data['detail'] ?? null) ? $data['detail'] : null;
        $transactionId = is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : null;

        return new self($status, $messageKey, $reason, $transactionId);
    }
}
