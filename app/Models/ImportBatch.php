<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'status',
    'jp_stock_filename',
    'us_stock_filename',
    'mutual_fund_filename',
    'imported_count',
    'error_count',
    'failure_reason',
    'imported_at',
])]
class ImportBatch extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(Snapshot::class);
    }

    public function summaryReport(): HasOne
    {
        return $this->hasOne(ImportSummaryReport::class);
    }
}
