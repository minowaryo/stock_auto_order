<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A watchlist entry (UC-012 / F-012 / ADR-0013): a favorite / manually
 * starred symbol tracked for new-investment screening. Indicators are shared
 * with held stocks via `holding_id` (technical_indicators /
 * fundamental_indicators / financial_statements / watch_records).
 */
#[Fillable([
    'holding_id',
    'folder_name',
    'exchange_label',
    'source',
    'is_starred',
    'last_seen_in_csv_at',
    'registered_at',
])]
class WatchlistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_starred' => 'boolean',
            'last_seen_in_csv_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
