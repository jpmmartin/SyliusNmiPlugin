<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use Symfony\Component\Intl\Currencies;

/**
 * Converts between Sylius's integer minor-unit amounts and the decimal string the gateway
 * uses ("x.xx"), in both directions, using the currency's own number of fraction digits and
 * no floating point anywhere.
 */
final class NmiAmountFormatter
{
    public function format(int $amount, string $currencyCode): string
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException(sprintf('Amounts sent to the gateway cannot be negative, got %d.', $amount));
        }

        $digits = Currencies::getFractionDigits(strtoupper($currencyCode));
        $divisor = 10 ** $digits;

        $whole = intdiv($amount, $divisor);
        $fraction = str_pad((string) ($amount % $divisor), $digits, '0', \STR_PAD_LEFT);

        // The gateway documents the format as "x.xx", so a zero-digit currency still gets two.
        return sprintf('%d.%s', $whole, str_pad($fraction, max(2, $digits), '0', \STR_PAD_RIGHT));
    }

    /**
     * Reads an amount back out of a gateway response. Negatives are accepted and preserved:
     * the gateway states a refund as a negative amount, and flattening the sign would lose
     * the one thing that distinguishes money returned from money taken.
     */
    public function parse(string $amount, string $currencyCode): int
    {
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($amount), $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an amount the gateway could have returned.', $amount));
        }

        $digits = Currencies::getFractionDigits(strtoupper($currencyCode));
        $fraction = $matches[3] ?? '';

        // Digits past the currency's precision must be zero. Rounding them away would move
        // money by a rounding rule nobody chose.
        $beyond = substr($fraction, $digits);
        if ('' !== $beyond && 0 !== (int) $beyond) {
            throw new \InvalidArgumentException(sprintf('"%s" carries more precision than %s has.', $amount, strtoupper($currencyCode)));
        }

        $significant = str_pad(substr($fraction, 0, $digits), $digits, '0', \STR_PAD_RIGHT);
        $minorUnits = (int) $matches[2] * (10 ** $digits) + (int) ('' === $significant ? '0' : $significant);

        return '-' === $matches[1] ? -$minorUnits : $minorUnits;
    }
}
