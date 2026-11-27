<?php

namespace App\Services\Finance;

use App\Models\AmpTarget;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FinancialLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Annual Management Plan: build the 12-month grid and save it (admin only). */
final class AmpService
{
    public function __construct(private FinancialCalculator $calc) {}

    /**
     * @param  array<int, array<int, string|null>>  $staged  line_id → month → amount (from an upload, unsaved)
     */
    public function grid(Company $company, int $year, array $staged = []): array
    {
        $lines = FinancialLine::forCompany($company->id)->get();
        $values = [];
        AmpTarget::where(['company_id' => $company->id, 'year' => $year])->get()
            ->each(function (AmpTarget $a) use (&$values) {
                $values[$a->financial_line_id][$a->month] = $a->amount;
            });

        $stagedKeys = [];
        foreach ($staged as $lineId => $months) {
            foreach ($months as $m => $v) {
                $values[$lineId][$m] = $v;
                $stagedKeys["{$lineId}.{$m}"] = true;
            }
        }

        $byCode = fn (callable $pick) => $lines->filter->isInput()
            ->mapWithKeys(fn ($l) => [$l->code => $pick($l)])->all();

        $columns = [];
        for ($m = 1; $m <= 12; $m++) {
            $columns[$m] = $this->calc->compute($lines, $byCode(fn ($l) => $values[$l->id][$m] ?? null));
        }
        $total = $this->calc->compute($lines, $byCode(
            fn ($l) => $this->calc->sum(...array_map(fn ($m) => $values[$l->id][$m] ?? null, range(1, 12)))
        ));

        $rows = $lines->map(fn (FinancialLine $line) => [
            'line' => $line,
            'months' => array_map(fn ($m) => [
                'value' => $columns[$m][$line->code] ?? null,
                'staged' => isset($stagedKeys["{$line->id}.{$m}"]),
            ], array_combine(range(1, 12), range(1, 12))),
            'total' => $total[$line->code] ?? null,
        ]);

        $gm = array_map(fn ($m) => $this->calc->grossMargin($columns[$m]['gross_profit'], $columns[$m]['total_revenue']), range(1, 12));

        return [
            'rows' => $rows,
            'grossMargin' => $gm,
            'totalMargin' => $this->calc->grossMargin($total['gross_profit'], $total['total_revenue']),
            'stagedCount' => count($stagedKeys),
            'definition' => $this->calc->definition($lines),
            'filled' => AmpTarget::where(['company_id' => $company->id, 'year' => $year])->distinct()->count('month'),
        ];
    }

    /**
     * @param  array<int|string, array<int|string, mixed>>  $cells  line_id → month → amount
     */
    public function save(Company $company, int $year, array $cells, User $by, string $source = 'grid'): int
    {
        $lines = FinancialLine::forCompany($company->id)->where('type', 'input')->get()->keyBy('id');
        $errors = [];
        $incoming = [];

        foreach ($cells as $lineId => $months) {
            $line = $lines->get((int) $lineId);
            if (! $line || ! is_array($months)) {
                $errors["amp.{$lineId}"] = 'Unknown line for this company.';

                continue;
            }
            foreach ($months as $m => $raw) {
                $m = (int) $m;
                if ($m < 1 || $m > 12) {
                    $errors["amp.{$lineId}.{$m}"] = 'Month must be 1-12.';

                    continue;
                }
                try {
                    $incoming[$line->id][$m] = Money::parse($raw);
                } catch (InvalidArgumentException) {
                    $errors["amp.{$lineId}.{$m}"] = "{$line->label} month {$m}: not a valid amount.";
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($company, $year, $incoming, $by, $source, $lines) {
            $existing = AmpTarget::where(['company_id' => $company->id, 'year' => $year])->lockForUpdate()->get()
                ->keyBy(fn ($a) => $a->financial_line_id.'.'.$a->month);
            $changed = 0;

            foreach ($incoming as $lineId => $months) {
                foreach ($months as $m => $new) {
                    $row = $existing->get("{$lineId}.{$m}");
                    $old = $row?->amount;
                    if ($old === $new || ($old !== null && $new !== null && Money::cmp($old, $new) === 0)) {
                        continue;
                    }
                    if ($new === null) {
                        $row->delete();
                    } elseif ($row) {
                        $row->update(['amount' => $new, 'updated_by' => $by->id]);
                    } else {
                        $row = AmpTarget::create([
                            'company_id' => $company->id, 'year' => $year, 'month' => $m,
                            'financial_line_id' => $lineId, 'amount' => $new, 'updated_by' => $by->id,
                        ]);
                    }
                    $changed++;
                    AuditLog::record("amp.{$source}", $row, [
                        'user_id' => $by->id, 'company_id' => $company->id, 'field' => 'amount', 'old' => $old, 'new' => $new,
                        'context' => ['year' => $year, 'month' => $m, 'line' => $lines[$lineId]->code],
                    ]);
                }
            }

            return $changed;
        });
    }
}
