<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\Exception\NmiGatewayException;

/**
 * One transaction as the gateway returned it. A JSON object, but the decision is still the
 * Classic triplet: `response` is 1, 2 or 3, and only the body says which — an HTTP 200 covers
 * an approval and a decline alike.
 *
 * `id` is the payment identifier and the path segment of every follow-on call. A refund is a
 * transaction of its own and carries a different one, so a caller that refunds must keep both.
 */
final class NmiResponse
{
    public const RESULT_APPROVED = 1;

    public const RESULT_DECLINED = 2;

    public const RESULT_ERROR = 3;

    /**
     * Observed states. The gateway types this as a free string and enumerates nothing, so
     * this list is what a sandbox produced, not a contract — never match on it exhaustively.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PENDING_SETTLEMENT = 'pendingsettlement';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_FAILED = 'failed';

    /**
     * @param array<int, array<string, mixed>> $actions
     * @param array<string, mixed>             $raw
     */
    private function __construct(
        public readonly int $result,
        public readonly string $responseText,
        public readonly int $responseCode,
        public readonly ?string $transactionId,
        public readonly ?string $authCode,
        public readonly ?string $avsResponse,
        public readonly ?string $cvvResponse,
        public readonly ?string $status,
        public readonly ?string $type,
        public readonly ?string $amount,
        public readonly ?string $currency,
        public readonly array $actions,
        public readonly array $raw,
    ) {
    }

    /** @throws NmiGatewayException when the body is not a transaction */
    public static function fromBody(string $body): self
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw NmiGatewayException::malformedResponse();
        }

        /** @var array<string, mixed> $raw */
        $raw = $decoded;

        $result = $raw['response'] ?? null;
        if (!is_scalar($result) || !in_array((string) $result, ['1', '2', '3'], true)) {
            throw NmiGatewayException::malformedResponse();
        }

        $string = static function (mixed $value): ?string {
            if (!is_scalar($value)) {
                return null;
            }
            $value = (string) $value;

            return '' === $value ? null : $value;
        };

        $actions = [];
        if (isset($raw['actions']) && is_array($raw['actions'])) {
            foreach ($raw['actions'] as $action) {
                if (!is_array($action)) {
                    continue;
                }

                /** @var array<string, mixed> $normalised */
                $normalised = $action;
                $actions[] = $normalised;
            }
        }

        return new self(
            result: (int) (string) $result,
            responseText: $string($raw['response_text'] ?? null) ?? '',
            responseCode: (int) ($string($raw['response_code'] ?? null) ?? '0'),
            transactionId: $string($raw['id'] ?? null),
            authCode: $string($raw['auth_code'] ?? null),
            avsResponse: $string($raw['avs_response'] ?? null),
            cvvResponse: $string($raw['cvv_response'] ?? null),
            status: $string($raw['status'] ?? null),
            type: $string($raw['type'] ?? null),
            amount: $string($raw['amount'] ?? null),
            currency: $string($raw['currency'] ?? null),
            actions: $actions,
            raw: $raw,
        );
    }

    public function isApproved(): bool
    {
        return self::RESULT_APPROVED === $this->result;
    }

    /**
     * True while the gateway will still accept a void. It is not the opposite of settled:
     * the gateway exposes no settled marker at all, so a false here means "the gateway has
     * already moved this on" — cancelled, failed, or a state this plugin has never seen.
     */
    public function isVoidable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PENDING_SETTLEMENT], true);
    }
}
