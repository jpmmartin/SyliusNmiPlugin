<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

/**
 * The gateway's other answer shape, returned with a 4xx status. Two kinds share it and the
 * status tells them apart: a schema violation carries `details` and no `error_code`, while a
 * refused-but-well-formed request carries an `error_code` and a message written for a human.
 */
final class NmiErrorResponse
{
    public const TYPE_VALIDATION = 'validationError';

    public const TYPE_INPUT = 'inputError';

    public const TYPE_AUTHENTICATION = 'authenticationError';

    /** @param array<int, array{fieldName?: string, message?: string}> $details */
    private function __construct(
        public readonly int $httpStatus,
        public readonly ?string $type,
        public readonly ?string $errorCode,
        public readonly string $message,
        public readonly ?string $referenceId,
        public readonly array $details,
    ) {
    }

    /** Returns null when the body is not one of these, so the caller can fall back. */
    public static function fromBody(int $httpStatus, string $body): ?self
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $message = $decoded['message'] ?? null;
        $type = $decoded['type'] ?? null;
        if (!is_string($message) || !is_string($type)) {
            return null;
        }

        $details = [];
        if (isset($decoded['details']) && is_array($decoded['details'])) {
            foreach ($decoded['details'] as $detail) {
                if (is_array($detail)) {
                    $details[] = [
                        'fieldName' => is_scalar($detail['fieldName'] ?? null) ? (string) $detail['fieldName'] : '',
                        'message' => is_scalar($detail['message'] ?? null) ? (string) $detail['message'] : '',
                    ];
                }
            }
        }

        $scalar = static fn (mixed $value): ?string => is_scalar($value) && '' !== (string) $value ? (string) $value : null;

        return new self(
            httpStatus: $httpStatus,
            type: $type,
            errorCode: $scalar($decoded['error_code'] ?? null),
            message: $message,
            referenceId: $scalar($decoded['ref_id'] ?? null),
            details: $details,
        );
    }

    /** The gateway's own wording, which is the only description of a refusal it gives. */
    public function describe(): string
    {
        if ([] === $this->details) {
            return $this->message;
        }

        $fields = array_map(
            static fn (array $detail): string => sprintf('%s: %s', $detail['fieldName'] ?? '', $detail['message'] ?? ''),
            $this->details,
        );

        return sprintf('%s (%s)', $this->message, implode('; ', $fields));
    }
}
