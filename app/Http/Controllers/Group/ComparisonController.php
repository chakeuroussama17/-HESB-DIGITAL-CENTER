<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Entry;
use App\Services\Finance\PerformanceService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Companies side by side on Revenue, GP, PBT and margin vs AMP, ranked. */
class ComparisonController extends Controller
{
    public function show(Request $request, PerformanceService $performance): View
    {
        $this->authorize('view-group');
        $year = $this->year($request);
        $month = (int) $request->query('month', 0);       // 0 = year to date
        $month = $month >= 1 && $month <= 12 ? $month : 0;
        $metric = $request->query('metric', 'total_revenue');
        $metric = array_key_exists($metric, PerformanceService::METRICS) ? $metric : 'total_revenue';

        $rows = Company::active()->orderBy('name')->get()->map(function (Company $c) use ($performance, $year, $month) {
            $h = $performance->headline($c, $year);
            $metrics = [];
            foreach (array_keys(PerformanceService::METRICS) as $code) {
                if ($month === 0) {
                    $k = $h['kpis'][$code];
                    $actual = $k['actual'];
                    $amp = $k['amp'];
                } else {
                    $actual = $h['chart']['series'][$code]['actual'][$month - 1];
                    $amp = $h['chart']['series'][$code]['amp'][$month - 1];
                }
                $isPct = $code === 'gross_margin';
                $metrics[$code] = [
                    'actual' => $actual,
                    'amp' => $amp,
                    'var' => $actual !== null || $amp !== null ? Money::sub($actual, $amp) : null,
                    'pct' => $actual !== null && $amp !== null && Money::cmp($amp, '0') > 0 ? Money::percent($actual, $amp) : null,
                    'isPercent' => $isPct,
                ];
            }

            return ['company' => $c, 'metrics' => $metrics];
        });

        // Rank on % of AMP for the chosen metric (companies without a plan go last).
        $ranked = $rows->sort(function ($a, $b) use ($metric) {
            $pa = $a['metrics'][$metric]['pct'];
            $pb = $b['metrics'][$metric]['pct'];
            if ($pa === null || $pb === null) {
                return ($pa === null) <=> ($pb === null);
            }

            return Money::cmp($pb, $pa);
        })->values();

        $chart = [
            'labels' => $ranked->map(fn ($r) => $r['company']->code)->all(),
            'names' => $ranked->map(fn ($r) => $r['company']->name)->all(),
            'colors' => $ranked->map(fn ($r) => $r['company']->color)->all(),
            'actual' => $ranked->map(fn ($r) => $r['metrics'][$metric]['actual'])->all(),
            'amp' => $ranked->map(fn ($r) => $r['metrics'][$metric]['amp'])->all(),
            'isPercent' => $metric === 'gross_margin',
            'radar' => [
                'axes' => array_values(PerformanceService::METRICS),
                'series' => $ranked->map(fn ($r) => [
                    'label' => $r['company']->code,
                    'color' => $r['company']->color,
                    'data' => array_map(fn ($m) => $m['pct'], array_values($r['metrics'])),
                ])->all(),
            ],
        ];

        return view('group.compare', [
            'year' => $year, 'month' => $month, 'metric' => $metric,
            'rows' => $ranked, 'chart' => $chart,
            'metrics' => PerformanceService::METRICS,
            'years' => $this->years(Entry::query()),
        ]);
    }
}
