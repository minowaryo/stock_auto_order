<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'snapshot_id',
    'holding_id',
    'quantity',
    'average_cost',
    'current_price',
    'fx_rate_used',
    'unrealized_gain_amount',
    'unrealized_gain_rate',
    'ma20',
    'ma75',
    'is_newly_detected',
])]
class HoldingSnapshot extends Model
{
    /**
     * Holding snapshots are append-only history rows (docs/architecture/data-model.md).
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
            'quantity' => 'decimal:2',
            'average_cost' => 'decimal:2',
            'current_price' => 'decimal:2',
            'fx_rate_used' => 'decimal:4',
            'unrealized_gain_amount' => 'decimal:2',
            'unrealized_gain_rate' => 'decimal:4',
            'ma20' => 'decimal:2',
            'ma75' => 'decimal:2',
            'is_newly_detected' => 'boolean',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
