<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One confirmed trade history import (UC-017, ADR-0027).
 */
#[Fillable([
    'status',
    'jp_filename',
    'us_filename',
    'period_from',
    'period_to',
    'total_rows',
    'new_rows',
    'existing_rows',
    'missing_rows',
    'failure_reason',
    'imported_at',
])]
class TradeImportBatch extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'total_rows' => 'integer',
            'new_rows' => 'integer',
            'existing_rows' => 'integer',
            'missing_rows' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function firstImportedExecutions(): HasMany
    {
        return $this->hasMany(TradeExecution::class, 'first_import_batch_id');
    }
}
