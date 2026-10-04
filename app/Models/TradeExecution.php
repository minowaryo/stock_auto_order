<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trade / transfer row from the full-history CSVs (UC-017, ADR-0027 D1).
 * Rows are never deleted; only last_seen_import_batch_id and
 * review_status change on re-import.
 */
#[Fillable([
    'holding_id',
    'market',
    'trade_date',
    'settlement_date',
    'account_type',
    'kind',
    'is_routine',
    'quantity',
    'unit_price',
    'price_currency',
    'settlement_currency',
    'settlement_amount_jpy',
    'settlement_amount_usd',
    'fx_rate',
    'fee_amount',
    'content_hash',
    'occurrence_index',
    'source_row',
    'first_import_batch_id',
    'last_seen_import_batch_id',
    'review_status',
])]
class TradeExecution extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'settlement_date' => 'date',
            'is_routine' => 'boolean',
            'occurrence_index' => 'integer',
            'source_row' => 'array',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }

    public function firstImportBatch(): BelongsTo
    {
        return $this->belongsTo(TradeImportBatch::class, 'first_import_batch_id');
    }

    public function lastSeenImportBatch(): BelongsTo
    {
        return $this->belongsTo(TradeImportBatch::class, 'last_seen_import_batch_id');
    }
}
