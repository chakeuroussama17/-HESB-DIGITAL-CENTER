<?php

namespace App\Services\Finance;

use App\Support\Money;

/**
 * Rule-based "Finance Guru Advisory". Reads the PerformanceService output and
 * returns a ranked list of plain-language insights. No external AI.
 *
 * Each insight: ['level' => bad|warn|good|info, 'title' => …, 'detail' => …, 'action' => ?string]
 */
final class FinanceGuru
{
    /** Thresholds (percent / percentage points). */
    public const REVENUE_WARN = -5;

    public const REVENUE_BAD = -10;

    public const MARGIN_WARN_PP = -1;

    public const COST_OVERRUN = 5;

    public function advise(array $d, bool $group = false): array
    {
        $out = [];
        $k = $d['kpis'];
        $who = $group ? 'The group' : 'You';

        // 1. Missing submissions
        $flash = $d['flash'];
        if ($flash['pendingActual']) {
            $names = collect($flash['pendingActual'])->pluck('name')->join(', ', ' and ');
            $out[] = $this->make('bad', "Actuals missing for {$flash['labels']['actual']}",
                $group ? "{$names} have not submitted actuals for the last finished week." : 'The last finished week has no actuals yet. Dashboards will understate performance until it is filled.',
                'entries');
        }
        if ($flash['pendingForecast']) {
            $names = collect($flash['pendingForecast'])->pluck('name')->join(', ', ' and ');
            $out[] = $this->make('warn', 'Forecast submission pending',
                ($group ? "{$names} still need" : 'You still need')." to submit forecasts for {$flash['labels']['fc1']} and {$flash['labels']['fc2']}.",
                'entries');
        }

        // 2. Revenue vs AMP
        $rev = $k['total_revenue'];
        if ($rev['pct'] !== null) {
            $gap = (float) Money::sub($rev['pct'], '100'); // display rounding only
            if ($gap <= self::REVENUE_WARN) {
                $out[] = $this->make($gap <= self::REVENUE_BAD ? 'bad' : 'warn',
                    sprintf('Revenue %.1f%% below AMP', abs($gap)),
                    sprintf('%s are RM %s behind plan year to date (RM %s actual vs RM %s AMP).',
                        $who, Money::format(ltrim(Money::sub($rev['amp'], $rev['actual']), '-')), Money::format($rev['actual']), Money::format($rev['amp'])));
            } elseif ($gap >= 2) {
                $out[] = $this->make('good', sprintf('Revenue %.1f%% ahead of AMP', $gap),
                    sprintf('Year-to-date revenue of RM %s is above the plan of RM %s.', Money::format($rev['actual']), Money::format($rev['amp'])));
            }
        }

        // 3. Margin vs plan
        $gm = $k['gross_margin'];
        if ($gm['delta'] !== null && (float) $gm['delta'] <= self::MARGIN_WARN_PP) {
            $out[] = $this->make('warn', sprintf('Gross margin %.1f pts below plan', abs((float) $gm['delta'])),
                sprintf('Margin is %s%% against a plan of %s%%. Check material and labour costs per unit of revenue.', $gm['actual'], $gm['amp']));
        }

        // 4. PBT
        $pbt = $k['pbt'];
        if ($pbt['actual'] !== null && Money::cmp($pbt['actual'], '0') < 0) {
            $out[] = $this->make('bad', 'Loss-making year to date',
                sprintf('Profit before tax is RM %s. AMP expected RM %s by now.', Money::format($pbt['actual']), Money::format($pbt['amp'])));
        } elseif ($pbt['pct'] !== null && (float) $pbt['pct'] < 90) {
            $out[] = $this->make('warn', sprintf('PBT at %s%% of AMP', $pbt['pct']),
                sprintf('Profit before tax is RM %s vs RM %s planned to date.', Money::format($pbt['actual']), Money::format($pbt['amp'])));
        }

        // 5. Biggest cost overrun (vs prorated AMP)
        $worst = null;
        foreach ($d['lineYtd'] as $line) {
            if (! $line['isCost'] || $line['actual'] === null || $line['amp'] === null || Money::cmp($line['amp'], '0') <= 0) {
                continue;
            }
            $over = Money::percent(Money::sub($line['actual'], $line['amp']), $line['amp']);
            if ($over !== null && (float) $over >= self::COST_OVERRUN && ($worst === null || (float) $over > (float) $worst['over'])) {
                $worst = $line + ['over' => $over];
            }
        }
        if ($worst) {
            $out[] = $this->make('warn', "{$worst['label']} {$worst['over']}% over plan",
                sprintf('RM %s spent vs RM %s in AMP to date. This is the largest cost overrun.', Money::format($worst['actual']), Money::format($worst['amp'])));
        }

        // 6. Momentum: last 4 weeks vs the 4 before
        $trend = $flash['rows']['total_revenue']['trend'];
        $recent = array_filter(array_slice($trend, -4), fn ($v) => $v !== null);
        $before = array_filter(array_slice($trend, 0, 4), fn ($v) => $v !== null);
        if (count($recent) >= 3 && count($before) >= 3) {
            $a = Money::add(...$recent);
            $b = Money::add(...$before);
            $chg = Money::percent(Money::sub($a, $b), $b);
            if ($chg !== null && abs((float) $chg) >= 5) {
                $up = (float) $chg > 0;
                $out[] = $this->make($up ? 'good' : 'warn', sprintf('Revenue momentum %s %s%%', $up ? 'up' : 'down', ltrim($chg, '-')),
                    'Last 4 weeks compared with the 4 weeks before.');
            }
        }

        // 7. Month landing on current forecast
        // (skipped while forecasts are missing: the projection would understate the month)
        if (($l = $d['landing']) && ! $flash['pendingForecast']) {
            if ($l['pct'] !== null) {
                $level = (float) $l['pct'] >= 100 ? 'good' : ((float) $l['pct'] >= 95 ? 'info' : 'warn');
                $out[] = $this->make($level, "{$l['month']} heading for {$l['pct']}% of AMP",
                    sprintf('Actuals so far plus forecasts for the remaining weeks give RM %s against a monthly AMP of RM %s.', Money::format($l['revenue']), Money::format($l['amp'])));
            }
        }

        if (! $out) {
            $out[] = $this->make('good', 'All on track', 'Submissions are complete and results are in line with AMP.');
        }

        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2, 'good' => 3];
        usort($out, fn ($x, $y) => $rank[$x['level']] <=> $rank[$y['level']]);

        return $out;
    }

    private function make(string $level, string $title, string $detail, ?string $action = null): array
    {
        return compact('level', 'title', 'detail', 'action');
    }
}
