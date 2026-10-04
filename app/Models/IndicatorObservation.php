<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of the indicators the weekly analysis obtained, with the
 * time the system obtained them (UC-018, ADR-0027 D4). Never updated or
 * deleted.
 */
#[Fillable([
    'holding_id',
    'source',
    'observed_at',
    'metrics',
])]
class IndicatorObservation extends Model
{
    /**
     * Append-only: the table has created_at but no updated_at.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'metrics' => 'array',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
