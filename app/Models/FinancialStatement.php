<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'holding_id',
    'fiscal_period',
    'revenue',
    'operating_income',
    'eps',
    'revenue_yoy_change',
    'operating_income_yoy_change',
    'fetched_at',
])]
class FinancialStatement extends Model
{
    /**
     * True historical table keyed by (holding_id, fiscal_period) — has
     * created_at (DB default) but no updated_at (docs/architecture/data-model.md
     * "financial_statements").
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
            'revenue' => 'decimal:2',
            'operating_income' => 'decimal:2',
            'eps' => 'decimal:2',
            'revenue_yoy_change' => 'decimal:4',
            'operating_income_yoy_change' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
