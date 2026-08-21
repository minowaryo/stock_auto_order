<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'holding_snapshot_id',
    'account_type',
    'quantity',
    'average_cost',
])]
class HoldingSnapshotAccount extends Model
{
    /**
     * Holding snapshot accounts are append-only history rows (docs/architecture/data-model.md).
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
        ];
    }

    public function holdingSnapshot(): BelongsTo
    {
        return $this->belongsTo(HoldingSnapshot::class);
    }
}
