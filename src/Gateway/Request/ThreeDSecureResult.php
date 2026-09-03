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
