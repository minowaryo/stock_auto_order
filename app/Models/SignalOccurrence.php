<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of fired signals with their determination-time metrics
 * (UC-014, ADR-0017 D3). Never updated or deleted.
 */
#[Fillable([
    'holding_id',
    'source',
    'signal_type',
    'observed_week',
    'snapshot_id',
    'metrics',
])]
class SignalOccurrence extends Model
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
            'observed_week' => 'date',
            'metrics' => 'array',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
