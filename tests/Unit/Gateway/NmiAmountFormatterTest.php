<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusNmiPlugin\Unit\Gateway;

use JpmMartin\SyliusNmiPlugin\Gateway\NmiAmountFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NmiAmountFormatterTest extends TestCase
{
    /** @return iterable<string, array{int, string, string}> */
    public static function amounts(): iterable
    {
        yield 'dollars and cents' => [1234, 'USD', '12.34'];
        yield 'cents only' => [5, 'USD', '0.05'];
        yield 'zero' => [0, 'EUR', '0.00'];
        yield 'lowercase currency' => [199, 'cad', '1.99'];
        yield 'zero-digit currency still gets two' => [1000, 'JPY', '1000.00'];
        yield 'three-digit currency' => [1234, 'KWD', '1.234'];
    }

    #[DataProvider('amounts')]
    public function testItFormatsMinorUnitsAsTheGatewayExpects(int $amount, string $currency, string $expected): void
    {
        self::assertSame($expected, (new NmiAmountFormatter())->format($amount, $currency));
    }

    public function testItRefusesNegativeAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NmiAmountFormatter())->format(-1, 'USD');
    }

    /**
     * Round-tripping is the property that matters: what the gateway sends back must come home
     * as the same integer Sylius started with.
     */
    #[DataProvider('amountsAndCurrencies')]
    public function testAnAmountSurvivesTheRoundTrip(int $minorUnits, string $currencyCode): void
    {
        $formatter = new NmiAmountFormatter();

        self::assertSame($minorUnits, $formatter->parse($formatter->format($minorUnits, $currencyCode), $currencyCode));
    }

    /** @return iterable<string, array{int, string}> */
    public static function amountsAndCurrencies(): iterable
    {
        yield 'nothing' => [0, 'USD'];
        yield 'a cent' => [1, 'USD'];
        yield 'under a unit' => [50, 'USD'];
        yield 'an ordinary order' => [12934, 'EUR'];
        yield 'a large one' => [99999999, 'USD'];
        yield 'a currency with no fraction' => [1500, 'JPY'];
        yield 'a currency with three digits' => [12345, 'BHD'];
    }

    /** A refund comes back negative, and the sign is the whole difference between two events. */
    public function testARefundIsReadBackAsNegative(): void
    {
        self::assertSame(-311, (new NmiAmountFormatter())->parse('-3.11', 'USD'));
    }

    public function testItReadsAnAmountWithNoFractionalPart(): void
    {
        self::assertSame(200, (new NmiAmountFormatter())->parse('2', 'USD'));
    }

    #[DataProvider('bodiesThatAreNotAmounts')]
    public function testSomethingThatIsNotAnAmountIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NmiAmountFormatter())->parse($value, 'USD');
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'words' => ['SUCCESS'];
        yield 'a thousands separator' => ['1,234.00'];
        yield 'a currency symbol' => ['$9.11'];
        yield 'scientific notation' => ['1e3'];
        yield 'two points' => ['1.2.3'];
    }

    /**
     * Rounding here would move money by a rule nobody chose, so it is refused instead.
     */
    public function testMorePrecisionThanTheCurrencyHasIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NmiAmountFormatter())->parse('9.115', 'USD');
    }

    /** Trailing zeros are not precision — the gateway pads every amount to two places. */
    public function testTrailingZerosBeyondTheCurrencyArePermitted(): void
    {
        self::assertSame(1500, (new NmiAmountFormatter())->parse('1500.00', 'JPY'));
    }
}
