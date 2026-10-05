<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only result of comparing the share count reconstructed from the
 * trade history with the holdings snapshot (UC-017).
 */
#[Fillable([
    'trade_import_batch_id',
    'snapshot_id',
    'holding_id',
    'account_type',
    'history_quantity',
    'snapshot_quantity',
    'status',
    'reason',
])]
class TradeReconciliationItem extends Model
{
    /**
     * Append-only: the table has created_at but no updated_at.
     */
    public const UPDATED_AT = null;

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function tradeImportBatch(): BelongsTo
    {
        return $this->belongsTo(TradeImportBatch::class);
    }
}
