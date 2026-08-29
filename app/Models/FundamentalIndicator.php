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
    'eps_growth',
    'peg_ratio',
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
            'eps_growth' => 'decimal:4',
            'peg_ratio' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }

    /**
     * equity_ratio/roe/revenue_growth/operating_income_growth as plain
     * nullable floats, in the parameter order FundamentalHealthEvaluator::
     * evaluate() and TakeProfitThresholdEvaluator::evaluate() expect.
     * Centralizes the decimal-cast-string-to-float/null-safe extraction that
     * every caller of those two evaluators otherwise has to repeat.
     *
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?float}
     */
    public function healthEvaluatorArgs(): array
    {
        return [
            $this->equity_ratio !== null ? (float) $this->equity_ratio : null,
            $this->roe !== null ? (float) $this->roe : null,
            $this->revenue_growth !== null ? (float) $this->revenue_growth : null,
            $this->operating_income_growth !== null ? (float) $this->operating_income_growth : null,
        ];
    }
}
