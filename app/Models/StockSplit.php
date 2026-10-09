<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock split of a holding (UC-018, ADR-0027 D6). Never updated except by
 * the upsert that corrects a ratio; never deleted.
 */
#[Fillable([
    'holding_id',
    'effective_date',
    'ratio_numerator',
    'ratio_denominator',
    'source',
])]
class StockSplit extends Model
{
    /**
     * The table has created_at but no updated_at.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'ratio_numerator' => 'integer',
            'ratio_denominator' => 'integer',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
