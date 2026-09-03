<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * No answer at all: DNS, connection, TLS or timeout. Nobody's fault, and the one case where
 * the outcome is unknown — the gateway may have taken the transaction. The message is
 * generic on purpose; the original exception is the previous one, so nothing an HTTP client
 * might echo from the request body reaches a message.
 */
final class NmiTransportException extends \RuntimeException implements NmiExceptionInterface
{
    public static function fromClientException(ClientExceptionInterface $exception): self
    {
        return new self(sprintf('The gateway could not be reached (%s).', $exception::class), 0, $exception);
    }

    /**
     * The gateway answered, but with a status that says nothing about the transaction: it was
     * rate limited, or something failed inside the gateway. Neither tells us whether the
     * request was processed, so it belongs here rather than with the gateway's own refusals.
     */
    public static function fromInconclusiveStatus(int $status): self
    {
        return new self(sprintf('The gateway answered with HTTP status %d, which does not say whether the transaction was processed.', $status));
    }
}
