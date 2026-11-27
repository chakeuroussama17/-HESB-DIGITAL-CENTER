<?php

namespace App\Services\Finance;

use App\Models\AmpTarget;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Entry;
use App\Models\Period;
use App\Models\User;
use App\Support\Money;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only code path that writes weekly actuals / forecasts. Used by the grid form
 * and by the Excel upload, so both get the same checks:
 *  - month not locked
 *  - line is an input line that belongs to this company (standard or its own custom)
 *  - week exists in that month
 *  - actuals only for weeks that have started
 *  - amounts valid DECIMAL(15,2)
 * Every changed value is written to the audit log with old and new value.
 */
final class EntryService
{
    /**
     * @param  array<int|string, array<int|string, array<string, mixed>>>  $cells  line_id → week → [actual, forecast]
     * @return int number of values that changed
     *
     * @throws ValidationException
     */
    public function saveMonth(Company $company, int $year, int $month, array $cells, User $by, string $source = 'grid'): int
    {
        if (Period::isLocked($company->id, $year, $month)) {
            throw ValidationException::withMessages([
                'period' => CarbonImmutable::create($year, $month)->format('F Y').' is locked. Ask HESB Admin to unlock it.',
            ]);
        }

        $lines = EntryGrid::inputLines($company);
        $weekCount = Week::count($year, $month);
        $today = CarbonImmutable::today();
        $errors = [];
        $incoming = [];

        foreach ($cells as $lineId => $byWeek) {
            $line = $lines->get((int) $lineId);
            if (! $line || ! is_array($byWeek)) {
                $errors["cells.{$lineId}"] = 'Unknown line for this company.';

                continue;
            }
            foreach ($byWeek as $week => $values) {
                $week = (int) $week;
                if ($week < 1 || $week > $weekCount || ! is_array($values)) {
                    $errors["cells.{$lineId}.{$week}"] = "Week {$week} does not exist in this month.";

                    continue;
                }
                [$start] = Week::range($year, $month, $week);
                foreach (EntryGrid::KINDS as $kind) {
                    if (! array_key_exists($kind, $values)) {
                        continue;
                    }
                    try {
                        $amount = Money::parse($values[$kind]);
                    } catch (InvalidArgumentException) {
                        $errors["cells.{$lineId}.{$week}.{$kind}"] = "{$line->label} Wk{$week} ".ucfirst($kind).': not a valid amount.';

                        continue;
                    }
                    if ($kind === 'actual' && $amount !== null && $start->greaterThan($today)) {
                        $errors["cells.{$lineId}.{$week}.actual"] = "{$line->label} Wk{$week}: actuals can't be entered before the week starts.";

                        continue;
                    }
                    $incoming[$line->id][$week][$kind] = $amount;
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($company, $year, $month, $incoming, $by, $source, $lines) {
            $existing = Entry::where(['company_id' => $company->id, 'year' => $year, 'month' => $month])
                ->lockForUpdate()->get()
                ->keyBy(fn (Entry $e) => $e->financial_line_id.'.'.$e->week);

            $changed = 0;
            $audit = [];
            $now = now();

            foreach ($incoming as $lineId => $byWeek) {
                foreach ($byWeek as $week => $values) {
                    $entry = $existing->get("{$lineId}.{$week}") ?? new Entry([
                        'company_id' => $company->id, 'year' => $year, 'month' => $month,
                        'week' => $week, 'financial_line_id' => $lineId,
                    ]);

                    $dirty = false;
                    foreach ($values as $kind => $new) {
                        $old = $entry->exists ? $entry->getAttribute($kind) : null;
                        if ($old === $new || ($old !== null && $new !== null && Money::cmp($old, $new) === 0)) {
                            continue;
                        }
                        $entry->setAttribute($kind, $new);
                        $dirty = true;
                        $changed++;
                        $audit[] = [
                            'user_id' => $by->id, 'company_id' => $company->id, 'action' => "entry.{$source}",
                            'auditable_type' => (new Entry)->getMorphClass(), 'auditable_id' => null,
                            'field' => $kind, 'old_value' => $old, 'new_value' => $new,
                            'context' => json_encode(['year' => $year, 'month' => $month, 'week' => $week, 'line' => $lines[$lineId]->code]),
                            'ip_address' => request()?->ip(), 'created_at' => $now,
                        ];
                    }

                    if ($dirty) {
                        $entry->updated_by = $by->id;
                        $entry->save();
                        foreach ($audit as &$row) {
                            $row['auditable_id'] ??= $entry->id;
                        }
                        unset($row);
                    }
                }
            }

            foreach (array_chunk($audit, 200) as $chunk) {
                AuditLog::insert($chunk);
            }

            // Automatically sync Forecast totals to AMP
            $this->syncForecastToAmp($company, $year, $month, $lines);

            return $changed;
        });
    }

    /**
     * Automatically copy forecast totals to AMP for the month.
     */
    private function syncForecastToAmp(Company $company, int $year, int $month, $lines): void
    {
        $entries = Entry::where(['company_id' => $company->id, 'year' => $year, 'month' => $month])
            ->whereNotNull('forecast')
            ->get();

        $forecastSums = $entries->groupBy('financial_line_id')->map(function ($lineEntries) {
            return $lineEntries->sum('forecast');
        });

        foreach ($forecastSums as $lineId => $total) {
            AmpTarget::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'year' => $year,
                    'month' => $month,
                    'financial_line_id' => $lineId,
                ],
                ['amount' => $total]
            );
        }
    }
}
