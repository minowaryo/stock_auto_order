<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Weekly price history of a holding (UC-014, ADR-0017 D2/D5). Overwrite-type
 * time series: UPSERTed by (holding_id, week_date) on every fetch.
 */
#[Fillable([
    'holding_id',
    'week_date',
    'close',
    'volume',
])]
class WeeklyPrice extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_date' => 'date',
            'close' => 'decimal:4',
            'volume' => 'integer',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
