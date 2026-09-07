<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Progress record of one watchlist bulk-refresh run (UC-012 / F-012 /
 * ADR-0013 D5). The screen polls this for the "更新中 42/150" indicator.
 */
#[Fillable([
    'status',
    'total_count',
    'processed_count',
    'failed_count',
    'started_at',
    'finished_at',
])]
class WatchlistRefreshRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * True while a run is queued or in progress (used to block a second
     * concurrent bulk refresh, UC-012 二重実行防止).
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING]);
    }
}
