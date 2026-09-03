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
}
