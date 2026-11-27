<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** History of Excel files uploaded to auto-fill the grid or AMP. */
#[Fillable(['company_id', 'user_id', 'kind', 'year', 'month', 'original_name', 'path', 'cells_changed'])]
class Upload extends Model
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
