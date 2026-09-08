<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiCardDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the shopper's browser is allowed to say about the card it tokenised.
 *
 * The values come from the payment component's own token lookup and are used for one thing: to
 * notice a card already on file before the gateway is asked to keep a second copy. That makes
 * them a convenience — and it makes the shape checks the only thing standing between an accepted
 * field and a card number in the store's database.
 */
final class NmiCardDetailsTest extends TestCase
{
    public function testItReadsWhatTheBrowserReported(): void
    {
        $card = NmiCardDetails::fromBrowserReport('visa', '1111', '1029');

        self::assertNotNull($card);
        self::assertSame('visa', $card->brand);
        self::assertSame('1111', $card->lastFour);
        self::assertSame(10, $card->expiryMonth);
        self::assertSame(2029, $card->expiryYear);
    }

    /** The one that matters: sixteen digits are not four, and are not believed. */
    public function testACardNumberIsNotFourDigits(): void
    {
        self::assertNull(NmiCardDetails::fromBrowserReport('visa', '4111111111111111', '1029'));
    }

    #[DataProvider('reportsThatAreNotBelieved')]
    public function testAReportThatDoesNotFitItsShapeIsRefused(mixed $brand, mixed $lastFour, mixed $expiry): void
    {
        self::assertNull(NmiCardDetails::fromBrowserReport($brand, $lastFour, $expiry));
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function reportsThatAreNotBelieved(): iterable
    {
        yield 'no brand' => [null, '1111', '1029'];
        yield 'an empty brand' => ['', '1111', '1029'];
        yield 'no digits' => ['visa', null, '1029'];
        yield 'three digits' => ['visa', '111', '1029'];
        yield 'five digits' => ['visa', '11111', '1029'];
        yield 'digits that are not digits' => ['visa', '11x1', '1029'];
        yield 'a masked number rather than the four digits' => ['visa', '411111******1111', '1029'];
        yield 'no expiry' => ['visa', '1111', null];
        yield 'a month that cannot exist' => ['visa', '1111', '1329'];
        yield 'a month of zero' => ['visa', '1111', '0029'];
        yield 'a four-digit year' => ['visa', '1111', '102029'];
        yield 'an expiry that is not a number' => ['visa', '1111', 'soon'];
        yield 'nothing at all' => [null, null, null];
        yield 'values that are not strings' => [['visa'], ['1111'], ['1029']];
    }
}
