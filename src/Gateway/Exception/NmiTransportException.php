<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * No answer at all: DNS, connection, TLS or timeout. Nobody's fault, and the one case where
 * the outcome is unknown — the gateway may have taken the transaction.
 *
 * **The URL is stripped and the original exception is not attached, and both of those are the
 * point.** Symfony's client writes `Could not resolve host: … for "https://…/api/v5/customers/
 * 1730549219"`, so the message of a failed vault delete carries the very reference this plugin
 * encrypts at rest — and a logger renders a whole exception chain, so attaching the original
 * would put it in the store's logs by a side door. What an operator needs is why it failed, not
 * which record it was reaching for, so the reason is kept and the address is not.
 */
final class NmiTransportException extends \RuntimeException implements NmiExceptionInterface
{
    public static function fromClientException(ClientExceptionInterface $exception): self
    {
        return new self(sprintf(
            'The gateway could not be reached (%s: %s).',
            $exception::class,
            self::withoutAddresses($exception->getMessage()),
        ));
    }

    /**
     * Every URL-shaped token out, wherever it sits.
     *
     * Anchoring on the ` for "…"` suffix Symfony happens to append today would be one release away
     * from letting an address through; this holds whatever shape the message takes.
     */
    private static function withoutAddresses(string $message): string
    {
        // Stops at the quote, so the phrase Symfony wraps the address in stays intact and can be
        // taken out whole rather than leaving a stray bracket behind.
        $stripped = preg_replace('#https?://[^\s"]+#i', '[the gateway]', $message) ?? $message;

        return trim(str_replace(' for "[the gateway]"', '', $stripped), " \t\n\r\0\x0B.");
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
