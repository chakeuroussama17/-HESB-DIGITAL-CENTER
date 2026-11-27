<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reported monthly total (AMP / Actual) for one metric, uploaded from a results sheet. */
#[Fillable(['company_id', 'year', 'month', 'metric', 'amp', 'actual', 'upload_id', 'updated_by'])]
class MonthlyResult extends Model
{
    public const METRICS = [
        'revenue' => 'Revenue',
        'gross_profit' => 'Gross Profit',
        'pbt' => 'PBT / (LBT)',
        'ebitda' => 'EBITDA',
    ];

    /** Reported metric → calculator code used across dashboards. */
    public const TO_CALC = [
        'revenue' => 'total_revenue',
        'gross_profit' => 'gross_profit',
        'pbt' => 'pbt',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amp' => 'decimal:2',
            'actual' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
