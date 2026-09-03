<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

use Symfony\Component\Intl\Currencies;

/**
 * Turns Sylius's integer minor-unit amounts into the decimal string the gateway expects
 * ("x.xx"), using the currency's own number of fraction digits and no floating point.
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
}
