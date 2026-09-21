<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Request;

/**
 * What the browser-side authentication handed back, forwarded with the charge as the
 * gateway's `cardholder_auth` object.
 *
 * Two field names differ from the component's: what it calls the cardholder authentication
 * result is the object's `status`, and the electronic commerce indicator is accepted
 * alongside it even though the published schema forbids extra fields there. Both were
 * established against the gateway rather than read.
 *
 * `cardholder_info` is deliberately absent: the gateway documents it as something to show
 * the shopper, never to send.
 */
final class ThreeDSecureResult
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $cavv = null,
        public readonly ?string $xid = null,
        public readonly ?string $eci = null,
        public readonly ?string $threeDsVersion = null,
        public readonly ?string $directoryServerId = null,
    ) {
    }

    /**
     * The result as the browser posts it into a payment request's payload, or null when it posted
     * none — the gateway rejects a body carrying fields it does not expect, so an empty
     * authentication object is omitted rather than sent.
     *
     * One reading for every request that carries a result, so that a charge and a card put on file
     * cannot come to disagree about which key holds which value.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $result = new self(
            status: self::text($payload['cardholder_auth'] ?? null),
            cavv: self::text($payload['cavv'] ?? null),
            xid: self::text($payload['xid'] ?? null),
            eci: self::text($payload['eci'] ?? null),
            threeDsVersion: self::text($payload['three_ds_version'] ?? null),
            directoryServerId: self::text($payload['directory_server_id'] ?? null),
        );

        return [] === $result->toArray() ? null : $result;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'cavv' => $this->cavv,
            'xid' => $this->xid,
            'eci' => $this->eci,
            'three_ds_version' => $this->threeDsVersion,
            'directory_server_id' => $this->directoryServerId,
        ], static fn (?string $value): bool => null !== $value && '' !== $value);
    }
}
