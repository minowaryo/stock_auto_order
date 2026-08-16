<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'holding_id',
    'per',
    'pbr',
    'roe',
    'revenue_growth',
    'operating_income_growth',
    'equity_ratio',
    'dividend_yield',
    'dividend_payout_ratio',
    'fetched_at',
])]
class FundamentalIndicator extends Model
{
    /**
     * Current-value UPSERT cache (docs/architecture/data-model.md): only
     * `fetched_at` tracks freshness, there is no created_at/updated_at pair.
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
            'per' => 'decimal:2',
            'pbr' => 'decimal:2',
            'roe' => 'decimal:4',
            'revenue_growth' => 'decimal:4',
            'operating_income_growth' => 'decimal:4',
            'equity_ratio' => 'decimal:4',
            'dividend_yield' => 'decimal:4',
            'dividend_payout_ratio' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
