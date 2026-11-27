<?php

namespace Database\Seeders;

use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic demo data for 2026 (RM '000).
 * - AMP for every month and input line.
 * - Actuals + forecasts for every week before "today".
 * - Forecasts only for the current week and the following weeks of this month.
 * - HICOM Engineering skips its forward forecasts so the "Forecast Submission
 *   Pending" badge and the advisory panel have something to show.
 */
class SampleDataSeeder extends Seeder
{
    public const YEAR = 2026;

    /** Share of revenue for each input line (other_income is signed). */
    private const MIX = [
        'revenue' => 1.0,
        'direct_materials' => 0.56,
        'direct_labor' => 0.12,
        'direct_overhead' => 0.08,
        'depreciation' => 0.05,
        'other_fixed_overhead' => 0.045,
        'admin_exp' => 0.055,
        'selling_distribution' => 0.025,
        'other_income' => 0.006,
        'finance_cost' => 0.012,
    ];

    /** Monthly AMP revenue base per company (RM '000). */
    private const SCALE = ['HAUTO' => 13542.21, 'HDC' => 6200, 'PHN' => 4800, 'HTS' => 3900, 'HENG' => 2600];

    public function run(): void
    {
        $today = CarbonImmutable::now();
        $lines = FinancialLine::standard()->where('type', 'input')->pluck('id', 'code');
        $now = now();

        foreach (Company::orderBy('id')->get() as $ci => $company) {
            $rng = new Randomizer(new Mt19937(1000 + $ci));
            $base = self::SCALE[$company->code] ?? 3000;
            $amp = [];
            $entries = [];

            for ($m = 1; $m <= 12; $m++) {
                $seasonal = 1 + 0.08 * sin(($m - 3) / 12 * 2 * M_PI);
                $monthRevenue = $base * $seasonal;

                foreach (self::MIX as $code => $share) {
                    $amp[] = [
                        'company_id' => $company->id, 'year' => self::YEAR, 'month' => $m,
                        'financial_line_id' => $lines[$code], 'amount' => $this->money($monthRevenue * $share),
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                $weeks = Week::count(self::YEAR, $m);
                // Company-level performance drift: some beat plan, some miss it.
                $drift = [1.04, 0.97, 1.01, 0.92, 0.88][$ci % 5];

                for ($w = 1; $w <= $weeks; $w++) {
                    [, $end] = Week::range(self::YEAR, $m, $w);
                    // A week is "past" once it has fully ended before today.
                    $isPast = $end->lessThan($today->startOfDay());
                    $isCurrentMonthFuture = ! $isPast && $m === $today->month && $today->year === self::YEAR;
                    if (! $isPast && ! $isCurrentMonthFuture) {
                        continue;
                    }
                    if (! $isPast && $company->code === 'HENG') {
                        continue; // forecast submission pending
                    }

                    $weekShare = ($w === 5 ? (CarbonImmutable::create(self::YEAR, $m)->daysInMonth - 28) : 7)
                        / CarbonImmutable::create(self::YEAR, $m)->daysInMonth;
                    $fcRevenue = $monthRevenue * $weekShare * $rng->getFloat(0.95, 1.05);
                    $actRevenue = $fcRevenue * $drift * $rng->getFloat(0.93, 1.07);

                    foreach (self::MIX as $code => $share) {
                        $costNoise = $code === 'revenue' ? 1 : $rng->getFloat(0.95, 1.06);
                        $entries[] = [
                            'company_id' => $company->id, 'year' => self::YEAR, 'month' => $m, 'week' => $w,
                            'financial_line_id' => $lines[$code],
                            'forecast' => $this->money($fcRevenue * $share),
                            'actual' => $isPast ? $this->money($actRevenue * $share * $costNoise) : null,
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
            }

            AmpTarget::where('company_id', $company->id)->where('year', self::YEAR)->delete();
            Entry::where('company_id', $company->id)->where('year', self::YEAR)->delete();
            foreach (array_chunk($amp, 500) as $chunk) {
                AmpTarget::insert($chunk);
            }
            foreach (array_chunk($entries, 500) as $chunk) {
                Entry::insert($chunk);
            }
        }
    }

    /** Seeder-only float → decimal string. Runtime code never uses floats. */
    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
