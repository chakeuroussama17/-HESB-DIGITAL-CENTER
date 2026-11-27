<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'company_id', 'action', 'auditable_type', 'auditable_id', 'field', 'old_value', 'new_value', 'context', 'ip_address'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Record one change. Called by the services that write financial data. */
    public static function record(string $action, ?Model $subject = null, array $attrs = []): self
    {
        $user = auth()->user();

        return static::create([
            'user_id' => $attrs['user_id'] ?? $user?->id,
            'company_id' => $attrs['company_id'] ?? $subject?->company_id ?? null,
            'action' => $action,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'field' => $attrs['field'] ?? null,
            'old_value' => $attrs['old'] ?? null,
            'new_value' => $attrs['new'] ?? null,
            'context' => $attrs['context'] ?? null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
