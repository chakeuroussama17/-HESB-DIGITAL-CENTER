<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\MonthlyResult;
use App\Models\Period;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Stores reported monthly totals exactly as uploaded.
 * - A blank cell in the file leaves the stored value unchanged.
 * - Actuals for locked months are skipped (AMP is a plan and isn't period-locked).
 */
final class MonthlyResultService
{
    /**
     * Compare an upload with what is stored.
     *
     * @param  array<int, array<int, array<string, array{amp: ?string, actual: ?string}>>>  $data
     * @return array{rows: list<array>, changes: int, skippedLocked: int}
     */
    public function preview(Company $company, array $data): array
    {
        $existing = $this->existing($company, array_keys($data));
        $rows = [];
        $changes = 0;
        $skipped = 0;

        foreach ($data as $year => $months) {
            foreach ($months as $month => $metrics) {
                $locked = Period::isLocked($company->id, $year, $month);
                foreach ($metrics as $metric => $values) {
                    $row = ['year' => $year, 'month' => $month, 'metric' => $metric, 'locked' => $locked];
                    foreach (['amp', 'actual'] as $kind) {
                        $old = $existing["{$year}.{$month}.{$metric}"]?->{$kind} ?? null;
                        $new = $values[$kind] ?? null;
                        $changed = $new !== null && ($old === null || Money::cmp($old, $new) !== 0);
                        $blocked = $changed && $kind === 'actual' && $locked;
                        $row[$kind] = ['old' => $old, 'new' => $new, 'changed' => $changed && ! $blocked, 'blocked' => $blocked];
                        $changes += (int) ($changed && ! $blocked);
                        $skipped += (int) $blocked;
                    }
                    $rows[] = $row;
                }
            }
        }

        return ['rows' => $rows, 'changes' => $changes, 'skippedLocked' => $skipped];
    }

    public function save(Company $company, array $data, User $by, ?int $uploadId = null): int
    {
        $preview = $this->preview($company, $data);

        return DB::transaction(function () use ($company, $preview, $by, $uploadId) {
            $changed = 0;
            foreach ($preview['rows'] as $row) {
                $updates = [];
                foreach (['amp', 'actual'] as $kind) {
                    if ($row[$kind]['changed']) {
                        $updates[$kind] = $row[$kind]['new'];
                    }
                }
                if (! $updates) {
                    continue;
                }
                $result = MonthlyResult::updateOrCreate(
                    ['company_id' => $company->id, 'year' => $row['year'], 'month' => $row['month'], 'metric' => $row['metric']],
                    $updates + ['upload_id' => $uploadId, 'updated_by' => $by->id],
                );
                foreach ($updates as $kind => $new) {
                    $changed++;
                    AuditLog::record('results.upload', $result, [
                        'user_id' => $by->id, 'company_id' => $company->id, 'field' => $kind,
                        'old' => $row[$kind]['old'], 'new' => $new,
                        'context' => ['year' => $row['year'], 'month' => $row['month'], 'line' => $row['metric']],
                    ]);
                }
            }

            return $changed;
        });
    }

    /** @return array<string, MonthlyResult> keyed "year.month.metric" */
    private function existing(Company $company, array $years): array
    {
        return MonthlyResult::where('company_id', $company->id)->whereIn('year', $years ?: [0])->get()
            ->keyBy(fn ($r) => "{$r->year}.{$r->month}.{$r->metric}")->all();
    }
}
