<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['holding_snapshot_id', 'signal_type', 'reason_summary'])]
class Signal extends Model
{
    /**
     * Signals are append-only history rows (docs/architecture/data-model.md).
     */
    const UPDATED_AT = null;

    public function holdingSnapshot(): BelongsTo
    {
        return $this->belongsTo(HoldingSnapshot::class);
    }
}
