<?php

namespace Tests\Unit;

use App\Services\Finance\FinancialCalculator;
use Database\Seeders\FinancialLineSeeder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FinancialCalculatorTest extends TestCase
{
    private FinancialCalculator $calc;

    /** @var list<array{code:string,type:string,group:?string}> */
    private array $lines;

    protected function setUp(): void
    {
        $this->calc = new FinancialCalculator;
        $this->lines = array_map(fn ($l) => [
            'code' => $l[0], 'type' => $l[2]->value, 'group' => $l[3]?->value,
        ], FinancialLineSeeder::LINES);
    }

    #[Test]
    public function screenshot_amp_gross_profit_reconciles(): void
    {
        // AMP column from the client's screenshot: Revenue 13,542.21, COS 11,622.91, GP 1,919.30.
        $r = $this->calc->compute($this->lines, [
            'revenue' => '13542.21',
            'direct_materials' => '7583.64',
            'direct_labor' => '1625.07',
            'direct_overhead' => '1083.38',
            'depreciation' => '677.11',
            'other_fixed_overhead' => '653.71',
        ]);

        $this->assertSame('13542.21', $r['total_revenue']);
        $this->assertSame('11622.91', $r['cost_of_sales']);
        $this->assertSame('1919.30', $r['gross_profit']);
    }

    #[Test]
    public function pbt_follows_the_agreed_formula(): void
    {
        // PBT = GP − Admin − S&D + Other income − Finance
        $r = $this->calc->compute($this->lines, [
            'revenue' => '1000.00',
            'direct_materials' => '500.00',
            'direct_labor' => '100.00',
            'direct_overhead' => '50.00',
            'depreciation' => '25.00',
            'other_fixed_overhead' => '25.00',
            'admin_exp' => '60.00',
            'selling_distribution' => '40.00',
            'other_income' => '15.50',
            'finance_cost' => '10.25',
        ]);

        $this->assertSame('700.00', $r['cost_of_sales']);
        $this->assertSame('300.00', $r['gross_profit']);
        $this->assertSame('205.25', $r['pbt']); // 300 − 60 − 40 + 15.50 − 10.25
    }

    #[Test]
    public function negative_other_income_reduces_pbt(): void
    {
        $r = $this->calc->compute($this->lines, ['revenue' => '100.00', 'other_income' => '-30.00']);
        $this->assertSame('70.00', $r['pbt']);
    }

    #[Test]
    public function custom_lines_roll_into_their_group(): void
    {
        $lines = [...$this->lines, ['code' => 'custom_tooling', 'type' => 'input', 'group' => 'cost_of_sales']];
        $r = $this->calc->compute($lines, ['revenue' => '100.00', 'direct_materials' => '40.00', 'custom_tooling' => '10.00']);

        $this->assertSame('50.00', $r['cost_of_sales']);
        $this->assertSame('50.00', $r['gross_profit']);
    }

    #[Test]
    public function blank_column_gives_blank_derived_rows(): void
    {
        $r = $this->calc->compute($this->lines, []);
        foreach (FinancialCalculator::DERIVED as $code) {
            $this->assertNull($r[$code]);
        }
    }

    #[Test]
    public function decimal_arithmetic_has_no_float_drift(): void
    {
        // 0.1 + 0.2 style sums must stay exact.
        $r = $this->calc->compute($this->lines, ['revenue' => '0.30', 'direct_materials' => '0.10', 'direct_labor' => '0.20']);
        $this->assertSame('0.00', $r['gross_profit']);

        $big = $this->calc->compute($this->lines, ['revenue' => '9999999999999.99', 'direct_materials' => '0.01']);
        $this->assertSame('9999999999999.98', $big['gross_profit']);
    }

    #[Test]
    public function margin_and_variance(): void
    {
        $this->assertSame('14.17', $this->calc->grossMargin('1919.30', '13542.21'));
        $this->assertNull($this->calc->grossMargin('10.00', '0.00'));

        $this->assertSame('-50.00', $this->calc->variance('950.00', '1000.00'));
        $this->assertSame('-5.00', $this->calc->variancePercent('950.00', '1000.00'));
        $this->assertNull($this->calc->variance(null, null));

        $this->assertFalse($this->calc->isFavourable('-50.00', isCost: false)); // revenue below plan
        $this->assertTrue($this->calc->isFavourable('-50.00', isCost: true));   // cost below plan
    }

    #[Test]
    public function sum_ignores_blanks_but_returns_null_when_all_blank(): void
    {
        $this->assertSame('3.50', $this->calc->sum('1.25', null, '2.25'));
        $this->assertNull($this->calc->sum(null, null));
    }

    #[Test]
    public function browser_definition_lists_input_groups(): void
    {
        $def = $this->calc->definition($this->lines);
        $this->assertSame('revenue', $def['inputs']['revenue']);
        $this->assertSame('other', $def['inputs']['other_income']);
        $this->assertArrayNotHasKey('gross_profit', $def['inputs']);
        $this->assertSame(FinancialCalculator::DERIVED, $def['derived']);
    }
}
