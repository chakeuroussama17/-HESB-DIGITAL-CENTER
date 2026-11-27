<?php

namespace App\Models;

use Database\Factories\AmpTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Annual Management Plan: the monthly target for one line. */
#[Fillable(['company_id', 'year', 'month', 'financial_line_id', 'amount', 'updated_by'])]
class AmpTarget extends Model
{
    /** @use HasFactory<AmpTargetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(FinancialLine::class, 'financial_line_id');
    }
}
