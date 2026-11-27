<?php

namespace App\Services\Finance;

use App\Enums\LineGroup;
use App\Enums\LineType;
use App\Models\FinancialLine;
use App\Support\Money;

/**
 * Single source of truth for every derived figure in the portal.
 *
 *   Total Revenue     = Σ revenue lines
 *   Cost of Sales     = Σ cost-of-sales lines (DM + DL + DOH + D&A + Other Fixed OH + custom)
 *   Gross Profit      = Total Revenue − Cost of Sales
 *   Profit Before Tax = Gross Profit − Σ opex (Admin, S&D) + Σ other income − Σ finance cost
 *
 * Costs are stored as positive numbers. Other Income/Expenses is signed (+ income).
 * The same rules are exported to the browser via definition() for live recalculation;
 * the server always recomputes on save, so the browser is display-only.
 */
final class FinancialCalculator
{
    public const TOTAL_REVENUE = 'total_revenue';

    public const COST_OF_SALES = 'cost_of_sales';

    public const GROSS_PROFIT = 'gross_profit';

    public const PBT = 'pbt';

    public const DERIVED = [self::TOTAL_REVENUE, self::COST_OF_SALES, self::GROSS_PROFIT, self::PBT];

    /** Display treatment of derived rows: which are presented as deductions. */
    public const DERIVED_IS_COST = [
        self::TOTAL_REVENUE => false,
        self::COST_OF_SALES => true,
        self::GROSS_PROFIT => false,
        self::PBT => false,
    ];

    /**
     * @param  iterable<FinancialLine|array{code:string,type:string|LineType,group:string|LineGroup|null}>  $lines
     * @param  array<string, string|null>  $values  input line code => amount
     * @return array<string, string|null> every input code and every derived code
     */
    public function compute(iterable $lines, array $values): array
    {
        $groups = array_fill_keys(array_map(fn (LineGroup $g) => $g->value, LineGroup::cases()), []);
        $result = [];
        $anyValue = false;

        foreach ($lines as $line) {
            [$code, $type, $group] = $this->describe($line);
            if ($type !== LineType::Input) {
                continue;
            }
            $v = $values[$code] ?? null;
            $result[$code] = $v;
            if ($v !== null) {
                $anyValue = true;
                if ($group !== null) {
                    $groups[$group->value][] = $v;
                }
            }
        }

        if (! $anyValue) {
            return $result + array_fill_keys(self::DERIVED, null);
        }

        $revenue = Money::add(...$groups[LineGroup::Revenue->value]);
        $cos = Money::add(...$groups[LineGroup::CostOfSales->value]);
        $gp = Money::sub($revenue, $cos);
        $opex = Money::add(...$groups[LineGroup::Opex->value]);
        $other = Money::add(...$groups[LineGroup::OtherIncome->value]);
        $finance = Money::add(...$groups[LineGroup::Finance->value]);
        $pbt = Money::sub(Money::add(Money::sub($gp, $opex), $other), $finance);

        return $result + [
            self::TOTAL_REVENUE => $revenue,
            self::COST_OF_SALES => $cos,
            self::GROSS_PROFIT => $gp,
            self::PBT => $pbt,
        ];
    }

    /** Gross margin % = Gross Profit ÷ Total Revenue × 100. */
    public function grossMargin(?string $grossProfit, ?string $revenue): ?string
    {
        return $grossProfit === null ? null : Money::percent($grossProfit, $revenue);
    }

    /** Var = Σ Actual − Σ Comparator (forecast or AMP). */
    public function variance(?string $actual, ?string $comparator): ?string
    {
        if ($actual === null && $comparator === null) {
            return null;
        }

        return Money::sub($actual, $comparator);
    }

    /** Var % relative to |comparator|. */
    public function variancePercent(?string $actual, ?string $comparator): ?string
    {
        $var = $this->variance($actual, $comparator);

        return $var === null ? null : Money::percent($var, $comparator);
    }

    /**
     * Is the variance good news? Income lines: actual above plan. Cost lines: below plan.
     * Returns null when there is nothing to compare.
     */
    public function isFavourable(?string $variance, bool $isCost): ?bool
    {
        if ($variance === null || Money::isZero($variance)) {
            return $variance === null ? null : true;
        }
        $positive = Money::cmp($variance, '0') > 0;

        return $isCost ? ! $positive : $positive;
    }

    /** Sum the same line across several columns (weeks / months / companies). */
    public function sum(?string ...$values): ?string
    {
        $present = array_filter($values, fn ($v) => $v !== null);

        return $present === [] ? null : Money::add(...$present);
    }

    /**
     * Shape the rules for the browser. resources/js/grid.js implements the identical
     * group sums using integer cents.
     *
     * @return array{inputs: array<string,string>, derived: list<string>}
     */
    public function definition(iterable $lines): array
    {
        $inputs = [];
        foreach ($lines as $line) {
            [$code, $type, $group] = $this->describe($line);
            if ($type === LineType::Input && $group !== null) {
                $inputs[$code] = $group->value;
            }
        }

        return ['inputs' => $inputs, 'derived' => self::DERIVED];
    }

    /** @return array{0:string, 1:LineType, 2:?LineGroup} */
    private function describe(FinancialLine|array $line): array
    {
        if ($line instanceof FinancialLine) {
            return [$line->code, $line->type, $line->group];
        }
        $type = $line['type'] instanceof LineType ? $line['type'] : LineType::from($line['type']);
        $group = $line['group'] ?? null;
        $group = $group === null || $group instanceof LineGroup ? $group : LineGroup::from($group);

        return [$line['code'], $type, $group];
    }
}
