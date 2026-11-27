<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Wk1..Wk5 inside a month: days 1-7, 8-14, 15-21, 22-28, 29-end.
 * February in a non-leap year therefore has only 4 weeks.
 */
final class Week
{
    public const MAX = 5;

    public static function count(int $year, int $month): int
    {
        return CarbonImmutable::create($year, $month, 1)->daysInMonth > 28 ? 5 : 4;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(int $year, int $month, int $week): array
    {
        $start = CarbonImmutable::create($year, $month, 1 + ($week - 1) * 7)->startOfDay();
        $end = $week >= 5 ? $start->endOfMonth() : $start->addDays(6)->endOfDay();

        return [$start, $end];
    }

    public static function of(CarbonInterface $date): int
    {
        return min(5, intdiv($date->day - 1, 7) + 1);
    }

    /**
     * The current week plus the next $ahead weeks (for Wk+1 / Wk+2 forecasts),
     * crossing month boundaries.
     *
     * @return list<array{year:int, month:int, week:int}>
     */
    public static function window(CarbonInterface $date, int $ahead = 2): array
    {
        $out = [['year' => $date->year, 'month' => $date->month, 'week' => self::of($date)]];
        for ($i = 0; $i < $ahead; $i++) {
            $last = end($out);
            $out[] = self::next($last['year'], $last['month'], $last['week']);
        }

        return $out;
    }

    /** @return array{year:int, month:int, week:int} */
    public static function next(int $year, int $month, int $week): array
    {
        if ($week < self::count($year, $month)) {
            return ['year' => $year, 'month' => $month, 'week' => $week + 1];
        }
        $d = CarbonImmutable::create($year, $month, 1)->addMonth();

        return ['year' => $d->year, 'month' => $d->month, 'week' => 1];
    }

    /** @return array{year:int, month:int, week:int} */
    public static function previous(int $year, int $month, int $week): array
    {
        if ($week > 1) {
            return ['year' => $year, 'month' => $month, 'week' => $week - 1];
        }
        $d = CarbonImmutable::create($year, $month, 1)->subMonth();

        return ['year' => $d->year, 'month' => $d->month, 'week' => self::count($d->year, $d->month)];
    }

    /** Weeks of ($year, $month) that had fully ended by $date (0 … count). */
    public static function completed(int $year, int $month, CarbonInterface $date): int
    {
        $done = 0;
        for ($w = 1; $w <= self::count($year, $month); $w++) {
            [, $end] = self::range($year, $month, $w);
            if ($end->lessThan($date->copy()->startOfDay())) {
                $done++;
            }
        }

        return $done;
    }

    public static function short(int $year, int $month, int $week): string
    {
        return 'Wk'.$week.' '.CarbonImmutable::create($year, $month, 1)->format('M');
    }

    public static function label(int $year, int $month, int $week): string
    {
        [$s, $e] = self::range($year, $month, $week);

        return sprintf('Wk%d (%s–%s)', $week, $s->format('j'), $e->format('j M'));
    }
}
