<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 押し目買い signal determined for an unheld watchlist symbol (UC-012 /
 * F-012 / ADR-0013 D3). Same signal_type domain as `buy_signals` (UC-010),
 * but keyed by holding_id because unheld symbols have no holding_snapshot.
 */
#[Fillable(['holding_id', 'signal_type', 'reason_summary', 'determined_at'])]
class WatchlistBuySignal extends Model
{
    /**
     * Append-only history rows: only determined_at / created_at, no updated_at.
     */
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'determined_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
