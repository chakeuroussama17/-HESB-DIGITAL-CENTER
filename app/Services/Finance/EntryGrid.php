<?php

namespace App\Services\Finance;

use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\Period;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the monthly data-entry grid (screenshot 1): weekly Act/Fc per line,
 * derived rows, Total Act, Total Fc, AMP, Var and Var %.
 */
final class EntryGrid
{
    public const KINDS = ['actual', 'forecast'];

    public function __construct(private FinancialCalculator $calc) {}

    /**
     * @param  array<int, array<int, array<string, string|null>>>  $staged  line_id → week → kind → value
     *                                                                      (values from an Excel upload awaiting save)
     */
    public function build(Company $company, int $year, int $month, array $staged = []): array
    {
        $lines = FinancialLine::forCompany($company->id)->get();
        $weekCount = Week::count($year, $month);
        $today = CarbonImmutable::today();

        $weeks = [];
        for ($w = 1; $w <= $weekCount; $w++) {
            [$start, $end] = Week::range($year, $month, $w);
            $weeks[$w] = [
                'number' => $w,
                'range' => $start->format('j').'–'.$end->format('j M'),
                'isCurrent' => $today->betweenIncluded($start, $end),
                'isFuture' => $start->greaterThan($today),
            ];
        }

        // line_id → week → kind → value
        $cells = [];
        Entry::where(['company_id' => $company->id, 'year' => $year, 'month' => $month])
            ->get(['financial_line_id', 'week', 'actual', 'forecast'])
            ->each(function (Entry $e) use (&$cells) {
                $cells[$e->financial_line_id][$e->week] = ['actual' => $e->actual, 'forecast' => $e->forecast];
            });

        $stagedKeys = [];
        foreach ($staged as $lineId => $byWeek) {
            foreach ($byWeek as $w => $byKind) {
                foreach ($byKind as $kind => $value) {
                    $cells[$lineId][$w][$kind] = $value;
                    $stagedKeys["{$lineId}.{$w}.{$kind}"] = true;
                }
            }
        }

        $amp = AmpTarget::where(['company_id' => $company->id, 'year' => $year, 'month' => $month])
            ->pluck('amount', 'financial_line_id');

        // Compute every column (week × kind) through the calculator.
        $byCode = fn (callable $value) => $lines->filter->isInput()
            ->mapWithKeys(fn (FinancialLine $l) => [$l->code => $value($l)])->all();

        $columns = [];
        foreach (array_keys($weeks) as $w) {
            foreach (self::KINDS as $kind) {
                $columns[$w][$kind] = $this->calc->compute($lines, $byCode(fn ($l) => $cells[$l->id][$w][$kind] ?? null));
            }
        }
        $totals = [];
        foreach (self::KINDS as $kind) {
            $totals[$kind] = $this->calc->compute($lines, $byCode(
                fn ($l) => $this->calc->sum(...array_map(fn ($w) => $cells[$l->id][$w][$kind] ?? null, array_keys($weeks)))
            ));
        }
        $ampColumn = $this->calc->compute($lines, $byCode(fn ($l) => $amp[$l->id] ?? null));

        $rows = $lines->map(function (FinancialLine $line) use ($weeks, $columns, $totals, $ampColumn, $stagedKeys) {
            $weekCells = [];
            foreach (array_keys($weeks) as $w) {
                foreach (self::KINDS as $kind) {
                    $weekCells[$w][$kind] = $columns[$w][$kind][$line->code] ?? null;
                    $weekCells[$w][$kind.'_staged'] = isset($stagedKeys["{$line->id}.{$w}.{$kind}"]);
                }
            }
            $act = $totals['actual'][$line->code] ?? null;
            $fc = $totals['forecast'][$line->code] ?? null;
            $var = $this->calc->variance($act, $fc);

            return [
                'line' => $line,
                'weeks' => $weekCells,
                'totalActual' => $act,
                'totalForecast' => $fc,
                'amp' => $ampColumn[$line->code] ?? null,
                'variance' => $var,
                'variancePct' => $this->calc->variancePercent($act, $fc),
                'favourable' => $this->calc->isFavourable($var, $line->isCost()),
            ];
        });

        return [
            'company' => $company,
            'year' => $year,
            'month' => $month,
            'monthName' => CarbonImmutable::create($year, $month)->format('F Y'),
            'weeks' => $weeks,
            'rows' => $rows,
            'locked' => Period::isLocked($company->id, $year, $month),
            'stagedCount' => count($stagedKeys),
            'definition' => $this->calc->definition($lines),
        ];
    }

    /** Lines a company may write to, keyed by id. */
    public static function inputLines(Company $company): Collection
    {
        return FinancialLine::forCompany($company->id)->where('type', 'input')->get()->keyBy('id');
    }
}
