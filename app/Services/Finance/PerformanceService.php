<?php

namespace App\Services\Finance;

use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\MonthlyResult;
use App\Support\Money;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dashboard numbers for one company or a consolidated set of companies.
 *
 * "vs AMP" compares year-to-date actuals with the AMP for the weeks that have
 * finished: past months count in full, the current month counts
 * completed-weeks / weeks-in-month, future months not at all.
 */
final class PerformanceService
{
    public const METRICS = [
        'total_revenue' => 'Revenue',
        'gross_profit' => 'Gross Profit',
        'pbt' => 'Profit Before Tax',
        'gross_margin' => 'Gross Margin',
    ];

    public function __construct(private FinancialCalculator $calc) {}

    /** @param Collection<int, Company> $companies */
    public function dashboard(Collection $companies, int $year, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $asOf = $this->asOf($year, $today);
        $raw = $this->load($companies, $year);
        $lines = $raw['lines'];

        // ---- per company & month: compute metrics, then let reported figures win ----
        $codes = ['total_revenue', 'gross_profit', 'pbt'];
        $weekly = [];                         // consolidated weekly inputs (flash)
        $ytd = ['actual' => [], 'amp' => []]; // input-level YTD (cost-overrun advisory)
        $sum = ['actual' => [], 'forecast' => [], 'amp' => []]; // [kind][month][code]
        $ytdActual = $ytdAmp = $fyAmp = array_fill_keys($codes, null);
        $anyData = false;
        $derive = fn (?array $inputs) => $this->calc->compute($lines, $inputs ?? []);
        $prorate = fn (string $v, array $f) => $f[0] === $f[1] ? $v
            : Money::normalise(bcdiv(bcmul($v, (string) $f[0], 6), (string) $f[1], 6));

        foreach ($companies as $company) {
            $cid = $company->id;
            for ($m = 1; $m <= 12; $m++) {
                $factor = [Week::completed($year, $m, $asOf), Week::count($year, $m)];
                $inputs = ['actual' => [], 'forecast' => []];
                foreach (($raw['weekly'][$cid][$m] ?? []) as $w => $kinds) {
                    foreach ($kinds as $kind => $values) {
                        foreach ($values as $code => $v) {
                            $this->add($weekly[$m][$w][$kind], $code, $v);
                            $this->add($inputs[$kind], $code, $v);
                            if ($kind === 'actual') {
                                $this->add($ytd['actual'], $code, $v);
                            }
                        }
                    }
                }
                foreach (($raw['amp'][$cid][$m] ?? []) as $code => $v) {
                    if ($factor[0] > 0) {
                        $this->add($ytd['amp'], $code, $prorate($v, $factor));
                    }
                }

                $d = [
                    'actual' => $derive($inputs['actual']),
                    'forecast' => $derive($inputs['forecast']),
                    'amp' => $derive($raw['amp'][$cid][$m] ?? null),
                ];
                foreach ($codes as $code) {
                    $rep = $raw['reported'][$cid][$m][$code] ?? [];
                    $act = $rep['actual'] ?? $d['actual'][$code];
                    $amp = $rep['amp'] ?? $d['amp'][$code];

                    $this->add($sum['actual'][$m], $code, $act);
                    $this->add($sum['forecast'][$m], $code, $d['forecast'][$code]);
                    $this->add($sum['amp'][$m], $code, $amp);
                    if ($act !== null) {
                        $ytdActual[$code] = Money::add($ytdActual[$code], $act);
                        $anyData = true;
                    }
                    if ($amp !== null) {
                        $fyAmp[$code] = Money::add($fyAmp[$code], $amp);
                        $anyData = true;
                        if ($factor[0] > 0) {
                            $ytdAmp[$code] = Money::add($ytdAmp[$code], $prorate($amp, $factor));
                        }
                    }
                }
            }
        }

        // ---- KPI gauges ----
        $kpis = [];
        foreach ($codes as $code) {
            $kpis[$code] = $this->kpi(self::METRICS[$code], $ytdActual[$code], $ytdAmp[$code], $fyAmp[$code]);
        }
        $gmAct = $this->calc->grossMargin($ytdActual['gross_profit'], $ytdActual['total_revenue']);
        $gmAmp = $this->calc->grossMargin($ytdAmp['gross_profit'], $ytdAmp['total_revenue']);
        $kpis['gross_margin'] = [
            'label' => 'Gross Margin', 'isPercent' => true,
            'actual' => $gmAct, 'amp' => $gmAmp,
            'fullYear' => $this->calc->grossMargin($fyAmp['gross_profit'], $fyAmp['total_revenue']),
            'delta' => $gmAct !== null && $gmAmp !== null ? Money::sub($gmAct, $gmAmp) : null,
            // gauge: margin as a share of the planned margin
            'pct' => $gmAct !== null && $gmAmp !== null && Money::cmp($gmAmp, '0') > 0 ? Money::percent($gmAct, $gmAmp) : null,
            // within 1 point of plan counts as "near"
            'status' => $gmAct === null || $gmAmp === null ? 'none'
                : (Money::cmp($gmAct, $gmAmp) >= 0 ? 'good' : (Money::cmp(Money::sub($gmAmp, $gmAct), '1') <= 0 ? 'near' : 'bad')),
        ];

        // ---- monthly chart ----
        $series = [];
        foreach (array_keys(self::METRICS) as $metric) {
            $series[$metric] = ['actual' => [], 'forecast' => [], 'amp' => []];
        }
        for ($m = 1; $m <= 12; $m++) {
            foreach (['actual', 'forecast', 'amp'] as $kind) {
                $v = $sum[$kind][$m] ?? [];
                foreach ($codes as $code) {
                    $series[$code][$kind][] = $v[$code] ?? null;
                }
                $series['gross_margin'][$kind][] = isset($v['gross_profit'])
                    ? $this->calc->grossMargin($v['gross_profit'], $v['total_revenue'] ?? null) : null;
            }
        }

        // ---- weekly flash ----
        $flash = $this->flash($companies, $raw, $weekly, $lines, $year, $today);

        // ---- per-line YTD (for the advisory) ----
        $lineYtd = [];
        foreach ($lines as $line) {
            if ($line->isInput()) {
                $lineYtd[$line->code] = [
                    'label' => $line->label, 'isCost' => $line->isCost(),
                    'actual' => $ytd['actual'][$line->code] ?? null, 'amp' => $ytd['amp'][$line->code] ?? null,
                ];
            }
        }

        // ---- current month landing: actual so far + forecast for remaining weeks ----
        $landing = null;
        if ($year === $today->year) {
            $m = $today->month;
            $mix = [];
            for ($w = 1; $w <= Week::count($year, $m); $w++) {
                $act = $weekly[$m][$w]['actual'] ?? [];
                $fc = $weekly[$m][$w]['forecast'] ?? [];
                foreach ($lines as $line) {
                    if ($line->isInput()) {
                        $this->add($mix, $line->code, $act[$line->code] ?? $fc[$line->code] ?? null);
                    }
                }
            }
            $landingD = $derive($mix);
            $monthAmp = $sum['amp'][$m]['total_revenue'] ?? null;
            $landing = [
                'month' => CarbonImmutable::create($year, $m)->format('F'),
                'revenue' => $landingD['total_revenue'], 'amp' => $monthAmp,
                'pct' => Money::percent($landingD['total_revenue'], $monthAmp),
            ];
        }

        return [
            'year' => $year,
            'asOf' => $asOf,
            'kpis' => $kpis,
            'chart' => [
                'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                'series' => $series,
                'metrics' => self::METRICS,
            ],
            'flash' => $flash,
            'lineYtd' => $lineYtd,
            'landing' => $landing,
            'hasData' => $anyData,
        ];
    }

    /** Headline YTD numbers per company, for the comparison table. */
    public function headline(Company $company, int $year, ?CarbonImmutable $today = null): array
    {
        $d = $this->dashboard(collect([$company]), $year, $today);

        return ['company' => $company, 'kpis' => $d['kpis'], 'flash' => $d['flash'], 'chart' => $d['chart']];
    }

    private function kpi(string $label, ?string $actual, ?string $amp, ?string $fullYear): array
    {
        $pct = $actual !== null && $amp !== null && Money::cmp($amp, '0') > 0 ? Money::percent($actual, $amp) : null;

        return [
            'label' => $label, 'isPercent' => false,
            'actual' => $actual, 'amp' => $amp, 'fullYear' => $fullYear,
            'delta' => $actual !== null || $amp !== null ? Money::sub($actual, $amp) : null,
            'pct' => $pct,
            'status' => $this->status($pct, $actual, $amp),
        ];
    }

    /** good: at or above plan; near: 95-100% of plan; bad: below that. */
    private function status(?string $pct, ?string $actual, ?string $amp): string
    {
        if ($actual === null || $amp === null) {
            return 'none';
        }
        if (Money::cmp($actual, $amp) >= 0) {
            return 'good';
        }

        return $pct !== null && Money::cmp($pct, '95') >= 0 ? 'near' : 'bad';
    }

    /**
     * Reporting week = the last finished week. Shows its actual, the forecast for
     * the next two weeks, an 8-week trend and which companies owe a submission.
     */
    private function flash(Collection $companies, array $raw, array $weekly, Collection $lines, int $year, CarbonImmutable $today): array
    {
        if ($year === $today->year) {
            $cur = ['year' => $today->year, 'month' => $today->month, 'week' => Week::of($today)];
            $ref = Week::previous(...$cur);
        } elseif ($year < $today->year) {
            $ref = ['year' => $year, 'month' => 12, 'week' => Week::count($year, 12)];
        } else {
            $ref = ['year' => $year, 'month' => 1, 'week' => 1];
        }
        $fc1 = Week::next(...$ref);
        $fc2 = Week::next(...$fc1);

        // Weeks outside $year (e.g. Jan Wk1 of next year) are simply blank.
        $cell = function (array $wk, string $kind) use ($weekly, $year) {
            return $wk['year'] === $year ? ($weekly[$wk['month']][$wk['week']][$kind] ?? null) : null;
        };

        $trendWeeks = [$ref];
        for ($i = 0; $i < 7; $i++) {
            array_unshift($trendWeeks, Week::previous(...$trendWeeks[0]));
        }

        $rows = [];
        $calc = fn ($vals) => $this->calc->compute($lines, $vals ?? []);
        $act = $calc($cell($ref, 'actual'));
        $f1 = $calc($cell($fc1, 'forecast'));
        $f2 = $calc($cell($fc2, 'forecast'));
        $trend = array_map(fn ($wk) => $calc($cell($wk, 'actual')), $trendWeeks);

        foreach (self::METRICS as $code => $label) {
            $pick = fn (array $d) => $code === 'gross_margin'
                ? $this->calc->grossMargin($d['gross_profit'], $d['total_revenue'])
                : $d[$code];
            $values = array_map($pick, $trend);
            $prev = $values[count($values) - 2] ?? null;
            $rows[$code] = [
                'label' => $label, 'isPercent' => $code === 'gross_margin',
                'actual' => $pick($act), 'fc1' => $pick($f1), 'fc2' => $pick($f2),
                'trend' => $values,
                'change' => $code === 'gross_margin'
                    ? ($pick($act) !== null && $prev !== null ? Money::sub($pick($act), $prev) : null)
                    : ($pick($act) !== null && $prev !== null ? Money::percent(Money::sub($pick($act), $prev), $prev) : null),
            ];
        }

        // Who still owes what (revenue line is the marker of a submission).
        $pendingForecast = [];
        $pendingActual = [];
        foreach ($companies as $c) {
            $has = fn (array $wk, string $kind) => $wk['year'] === $year
                && ($raw['weekly'][$c->id][$wk['month']][$wk['week']][$kind]['revenue'] ?? null) !== null;
            if (! $has($fc1, 'forecast') || ! $has($fc2, 'forecast')) {
                $pendingForecast[] = $c;
            }
            if ($ref['year'] === $year && ! $has($ref, 'actual')) {
                $pendingActual[] = $c;
            }
        }

        return [
            'ref' => $ref, 'fc1' => $fc1, 'fc2' => $fc2,
            'labels' => [
                'actual' => Week::short(...$ref).' Act',
                'fc1' => Week::short(...$fc1).' Fc',
                'fc2' => Week::short(...$fc2).' Fc',
                'trend' => array_map(fn ($wk) => Week::short(...$wk), $trendWeeks),
            ],
            'rows' => $rows,
            'pendingForecast' => $pendingForecast,
            'pendingActual' => $pendingActual,
        ];
    }

    private function asOf(int $year, CarbonImmutable $today): CarbonImmutable
    {
        return match (true) {
            $year < $today->year => CarbonImmutable::create($year + 1, 1, 1),
            $year > $today->year => CarbonImmutable::create($year, 1, 1),
            default => $today,
        };
    }

    /** @return array{lines: Collection, weekly: array, amp: array} */
    private function load(Collection $companies, int $year): array
    {
        $ids = $companies->pluck('id')->all();
        // All lines incl. archived custom ones: their history still counts.
        $lines = FinancialLine::query()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $ids))
            ->orderBy('sort')->orderBy('id')->get();
        $codes = $lines->pluck('code', 'id');

        $weekly = [];
        Entry::whereIn('company_id', $ids)->where('year', $year)
            ->get(['company_id', 'month', 'week', 'financial_line_id', 'actual', 'forecast'])
            ->each(function (Entry $e) use (&$weekly, $codes) {
                $code = $codes[$e->financial_line_id] ?? null;
                if ($code === null) {
                    return;
                }
                foreach (['actual', 'forecast'] as $kind) {
                    if ($e->{$kind} !== null) {
                        $weekly[$e->company_id][$e->month][$e->week][$kind][$code] = $e->{$kind};
                    }
                }
            });

        $amp = [];
        AmpTarget::whereIn('company_id', $ids)->where('year', $year)
            ->get(['company_id', 'month', 'financial_line_id', 'amount'])
            ->each(function (AmpTarget $a) use (&$amp, $codes) {
                if ($code = $codes[$a->financial_line_id] ?? null) {
                    $amp[$a->company_id][$a->month][$code] = $a->amount;
                }
            });

        // Reported monthly totals (uploaded results sheets), keyed by calculator code.
        $reported = [];
        MonthlyResult::whereIn('company_id', $ids)->where('year', $year)
            ->whereIn('metric', array_keys(MonthlyResult::TO_CALC))
            ->get(['company_id', 'month', 'metric', 'amp', 'actual'])
            ->each(function (MonthlyResult $r) use (&$reported) {
                $reported[$r->company_id][$r->month][MonthlyResult::TO_CALC[$r->metric]] = ['amp' => $r->amp, 'actual' => $r->actual];
            });

        return ['lines' => $lines, 'weekly' => $weekly, 'amp' => $amp, 'reported' => $reported];
    }

    private function add(?array &$bucket, string $code, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        $bucket ??= [];
        $bucket[$code] = isset($bucket[$code]) ? Money::add($bucket[$code], $value) : $value;
    }
}
