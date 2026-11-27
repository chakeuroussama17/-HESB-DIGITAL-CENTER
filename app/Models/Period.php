<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Month lock. A row with company_id NULL locks the month for every company;
 * a company-specific row overrides it either way.
 */
#[Fillable(['company_id', 'year', 'month', 'is_locked', 'locked_by', 'locked_at'])]
class Period extends Model
{
    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public static function isLocked(int $companyId, int $year, int $month): bool
    {
        $rows = static::query()
            ->where('year', $year)->where('month', $month)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->get();

        $specific = $rows->firstWhere('company_id', $companyId);
        if ($specific) {
            return $specific->is_locked;
        }

        return (bool) $rows->firstWhere('company_id', null)?->is_locked;
    }
}
