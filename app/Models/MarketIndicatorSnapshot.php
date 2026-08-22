<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'snapshot_id',
    'index_name',
    'value',
    'change_rate',
    'ma_deviation',
])]
class MarketIndicatorSnapshot extends Model
{
    /**
     * Market indicator snapshots are append-only history rows
     * (docs/architecture/data-model.md): only created_at is kept.
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'change_rate' => 'decimal:4',
            'ma_deviation' => 'decimal:4',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
