<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'holding_id',
    'rsi',
    'macd',
    'macd_signal',
    'ma20',
    'ma75',
    'bb_upper',
    'bb_lower',
    'volume',
    'volume_ma20',
    'week52_high',
    'week52_low',
    'relative_strength_vs_market',
    'relative_strength_vs_sector',
    'computed_at',
])]
class TechnicalIndicator extends Model
{
    /**
     * Current-value UPSERT cache (docs/architecture/data-model.md): only
     * `computed_at` tracks freshness, there is no created_at/updated_at pair.
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rsi' => 'decimal:2',
            'macd' => 'decimal:4',
            'macd_signal' => 'decimal:4',
            'ma20' => 'decimal:2',
            'ma75' => 'decimal:2',
            'bb_upper' => 'decimal:2',
            'bb_lower' => 'decimal:2',
            'volume' => 'integer',
            'volume_ma20' => 'decimal:2',
            'week52_high' => 'decimal:2',
            'week52_low' => 'decimal:2',
            'relative_strength_vs_market' => 'decimal:4',
            'relative_strength_vs_sector' => 'decimal:4',
            'computed_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
