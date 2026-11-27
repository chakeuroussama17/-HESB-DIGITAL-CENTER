<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\Period;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Companies × weeks: has each company submitted its actual and forecast?
 * A week counts as submitted when its Revenue line has a value.
 */
class TrackerController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorize('view-group');
        $year = $this->year($request);
        $today = CarbonImmutable::today();
        $month = (int) $request->query('month', $year === $today->year ? $today->month : 12);
        $month = max(1, min(12, $month));

        $companies = Company::active()->orderBy('name')->get();
        $revenueId = FinancialLine::standard()->where('code', 'revenue')->value('id');
        $window = collect(Week::window($today))->slice(1)->map(fn ($w) => "{$w['year']}-{$w['month']}-{$w['week']}")->all();

        $entries = Entry::whereIn('company_id', $companies->pluck('id'))
            ->where('year', $year)->where('financial_line_id', $revenueId)
            ->get(['company_id', 'month', 'week', 'actual', 'forecast']);
        $byKey = $entries->keyBy(fn ($e) => "{$e->company_id}.{$e->month}.{$e->week}");

        // Week matrix for the chosen month
        $weeks = [];
        for ($w = 1; $w <= Week::count($year, $month); $w++) {
            [$start, $end] = Week::range($year, $month, $w);
            $weeks[$w] = ['range' => $start->format('j').'–'.$end->format('j M'), 'ended' => $end->lessThan($today),
                'started' => ! $start->greaterThan($today), 'key' => "{$year}-{$month}-{$w}"];
        }

        $matrix = [];
        $totals = ['submitted' => 0, 'pending' => 0];
        foreach ($companies as $c) {
            foreach ($weeks as $w => $info) {
                $e = $byKey->get("{$c->id}.{$month}.{$w}");
                $actual = match (true) {
                    $e?->actual !== null => 'submitted',
                    $info['ended'] => 'pending',
                    default => 'not-due',
                };
                $forecast = match (true) {
                    $e?->forecast !== null => 'submitted',
                    in_array($info['key'], $window, true) => 'pending',
                    ! $info['started'] => 'open',
                    default => 'missed',
                };
                foreach ([$actual, $forecast] as $s) {
                    if (isset($totals[$s])) {
                        $totals[$s]++;
                    }
                }
                $matrix[$c->id][$w] = compact('actual', 'forecast');
            }
        }

        // Year overview: % of finished weeks with actuals, per company per month
        $overview = [];
        foreach ($companies as $c) {
            for ($m = 1; $m <= 12; $m++) {
                $due = Week::completed($year, $m, $today);
                if ($due === 0) {
                    $overview[$c->id][$m] = null;

                    continue;
                }
                $done = 0;
                for ($w = 1; $w <= $due; $w++) {
                    if ($byKey->get("{$c->id}.{$m}.{$w}")?->actual !== null) {
                        $done++;
                    }
                }
                $overview[$c->id][$m] = (int) round($done / $due * 100);
            }
        }

        return view('group.tracker', [
            'year' => $year, 'month' => $month, 'monthName' => CarbonImmutable::create($year, $month)->format('F Y'),
            'companies' => $companies, 'weeks' => $weeks, 'matrix' => $matrix, 'totals' => $totals,
            'overview' => $overview, 'locked' => $companies->mapWithKeys(fn ($c) => [$c->id => Period::isLocked($c->id, $year, $month)]),
            'years' => $this->years(Entry::query()),
        ]);
    }
}
