<?php

namespace App\Models;

use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekly cell pair (Act / Fc) for one line. Amounts are DECIMAL(15,2) and are
 * cast to strings ("decimal:2") so they never pass through floats.
 */
#[Fillable(['company_id', 'year', 'month', 'week', 'financial_line_id', 'actual', 'forecast', 'updated_by'])]
class Entry extends Model
{
    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'week' => 'integer',
            'actual' => 'decimal:2',
            'forecast' => 'decimal:2',
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

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
