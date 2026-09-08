<?php

declare(strict_types=1);

namespace JpmMartin\SyliusNmiPlugin\Gateway;

/**
 * How the gateway describes a card once it holds it: the four fields a shopper needs in order to
 * recognise which card they are choosing, and nothing that could be used to charge one.
 *
 * **The masking is the gateway's, not this plugin's.** It returns `411111******1111`, so the last
 * four digits are read out of what it sent rather than derived from a number this package would
 * then have had to hold. Nothing here is ever a full card number.
 *
 * The expiry arrives as four digits, month then a two-digit year — `1030` is October 2030 — which
 * is the same shape the gateway accepts back, and not a date this plugin gets to reinterpret.
 */
final class NmiCardDetails
{
    private function __construct(
        public readonly string $brand,
        public readonly string $lastFour,
        public readonly int $expiryMonth,
        public readonly int $expiryYear,
    ) {
    }

    /**
     * Reads the `payment_details` object a charge comes back with.
     *
     * Null when the gateway described the card in a way this cannot read. That is a defensive
     * branch rather than an expected one — every vaulting charge observed against the sandbox
     * carried all three keys — and its consequence is stated where it is handled: the payment
     * still succeeds and no card is stored, because a card nobody can recognise in a list is
     * worse than no card at all.
     *
     * **The account-area path gets no brand, and that was settled against the gateway.** Creating a
     * vault record answers with `card_number` and `card_exp` and nothing else, while the charge
     * that stores a card answers with `card_type` too — so the same shape cannot be assumed of
     * both. `$brandWhenTheGatewayIsSilent` is what the browser reported about the card it just
     * tokenised, used only when the gateway named no brand: the digits and the expiry stay the
     * gateway's, which are the two a shopper recognises a card by.
     *
     * @param array<string, mixed> $paymentDetails
     */
    public static function fromPaymentDetails(array $paymentDetails, ?string $brandWhenTheGatewayIsSilent = null): ?self
    {
        $brand = self::text($paymentDetails['card_type'] ?? null) ?? self::text($brandWhenTheGatewayIsSilent);
        $number = self::text($paymentDetails['card_number'] ?? null);
        $expiry = self::text($paymentDetails['card_exp'] ?? null);

        if (null === $number) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($digits) < 4) {
            return null;
        }

        return self::from($brand, substr($digits, -4), $expiry);
    }

    /**
     * What the shopper's browser said about the card it just tokenised, used only to notice a card
     * already on file before the gateway is asked to keep a second copy of it.
     *
     * **It takes the last four digits, never a number.** Every field is checked into its own
     * shape, so a browser that posts sixteen digits here is refused rather than believed: the one
     * thing this plugin must never do is hold a card number, and an accepted field is a place one
     * could otherwise land.
     */
    public static function fromBrowserReport(mixed $brand, mixed $lastFour, mixed $expiry): ?self
    {
        $lastFour = self::text($lastFour);
        if (null === $lastFour || 1 !== preg_match('/^\d{4}$/', $lastFour)) {
            return null;
        }

        return self::from(self::text($brand), $lastFour, self::text($expiry));
    }

    private static function from(?string $brand, string $lastFour, ?string $expiry): ?self
    {
        if (null === $brand || null === $expiry) {
            return null;
        }

        // Four digits, month then year, and nothing else. The gateway offers no century, and a
        // card cannot have expired before the scheme existed, so 2000 is the only reading.
        if (1 !== preg_match('/^(0[1-9]|1[0-2])(\d{2})$/', $expiry, $matches)) {
            return null;
        }

        return new self(
            brand: $brand,
            lastFour: $lastFour,
            expiryMonth: (int) $matches[1],
            expiryYear: 2000 + (int) $matches[2],
        );
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
