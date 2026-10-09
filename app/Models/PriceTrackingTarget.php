<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-holding state of the weekly price tracking (UC-018, ADR-0024 D1・D2,
 * ADR-0027 D7). One row per holding; updated by PriceTrackingTargetUpdater.
 */
#[Fillable([
    'holding_id',
    'last_sell_week',
    'last_signal_week',
    'track_until_week',
    'status',
    'latest_saved_week',
    'backfilled_from_week',
    'consecutive_failures',
    'splits_incomplete',
    'last_error',
    'last_attempted_at',
])]
class PriceTrackingTarget extends Model
{
    /**
     * Column defaults, so a freshly created model reads like the stored row.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'consecutive_failures' => 0,
        'splits_incomplete' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sell_week' => 'date',
            'last_signal_week' => 'date',
            'track_until_week' => 'date',
            'latest_saved_week' => 'date',
            'backfilled_from_week' => 'date',
            'consecutive_failures' => 'integer',
            'splits_incomplete' => 'boolean',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
