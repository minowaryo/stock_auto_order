<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Weekly price history of a market index (UC-014, ADR-0017 D2).
 * UPSERTed by (index_name, week_date) on every fetch.
 */
#[Fillable([
    'index_name',
    'week_date',
    'close',
])]
class IndexWeeklyPrice extends Model
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
        ];
    }
}
