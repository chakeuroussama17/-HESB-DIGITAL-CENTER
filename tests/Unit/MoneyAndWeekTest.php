<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\Week;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyAndWeekTest extends TestCase
{
    public static function parseCases(): array
    {
        return [
            ['1,234.5', '1234.50'],
            ['(1,234.50)', '-1234.50'],
            ['-12', '-12.00'],
            ['RM 1 000', '1000.00'],
            ['0.005', '0.01'],
            ['-0.004', '0.00'],
            [42, '42.00'],
            [1234.565, '1234.57'],
            ['', null],
            [null, null],
        ];
    }

    #[Test]
    #[DataProvider('parseCases')]
    public function it_parses_amounts(mixed $in, ?string $out): void
    {
        $this->assertSame($out, Money::parse($in));
    }

    #[Test]
    public function it_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('12abc');
    }

    #[Test]
    public function it_rejects_amounts_beyond_decimal_15_2(): void
    {
        $this->assertFalse(Money::isValid('99999999999999.00'));
        $this->assertTrue(Money::isValid('9999999999999.99'));
    }

    #[Test]
    public function it_formats_in_accounting_style(): void
    {
        $this->assertSame('13,542.21', Money::format('13542.21'));
        $this->assertSame('(623.47)', Money::format('-623.47'));
        $this->assertSame('(11,622.91)', Money::format('11622.91', asCost: true));
        $this->assertSame('5.00', Money::format('-5.00', asCost: true)); // a credit on a cost line
        $this->assertSame('–', Money::format(null));
    }

    #[Test]
    public function weeks_split_the_month_into_seven_day_blocks(): void
    {
        $this->assertSame(5, Week::count(2026, 1));
        $this->assertSame(4, Week::count(2026, 2));   // 28 days
        $this->assertSame(5, Week::count(2028, 2));   // leap year

        [$s, $e] = Week::range(2026, 11, 5);
        $this->assertSame('2026-11-29', $s->toDateString());
        $this->assertSame('2026-11-30', $e->toDateString());

        $this->assertSame(3, Week::of(CarbonImmutable::parse('2026-11-16')));
        $this->assertSame(5, Week::of(CarbonImmutable::parse('2026-12-31')));
    }

    #[Test]
    public function the_forecast_window_crosses_month_ends(): void
    {
        $w = Week::window(CarbonImmutable::parse('2026-02-25'));
        $this->assertSame([
            ['year' => 2026, 'month' => 2, 'week' => 4],
            ['year' => 2026, 'month' => 3, 'week' => 1],
            ['year' => 2026, 'month' => 3, 'week' => 2],
        ], $w);
    }
}
