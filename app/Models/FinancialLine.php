<?php

namespace App\Models;

use App\Enums\LineGroup;
use App\Enums\LineType;
use App\Services\Finance\FinancialCalculator;
use Database\Factories\FinancialLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'code', 'label', 'type', 'group', 'sort', 'is_active'])]
class FinancialLine extends Model
{
    /** @use HasFactory<FinancialLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => LineType::class,
            'group' => LineGroup::class,
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Standard lines + this company's own custom lines, in display order. */
    public function scopeForCompany(Builder $q, int $companyId): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderBy('sort')->orderBy('id');
    }

    public function scopeStandard(Builder $q): Builder
    {
        return $q->whereNull('company_id');
    }

    public function isInput(): bool
    {
        return $this->type === LineType::Input;
    }

    public function isCustom(): bool
    {
        return $this->company_id !== null;
    }

    /** Presented as a deduction (bracketed)? */
    public function isCost(): bool
    {
        return $this->isInput()
            ? (bool) $this->group?->isCost()
            : (FinancialCalculator::DERIVED_IS_COST[$this->code] ?? false);
    }
}
