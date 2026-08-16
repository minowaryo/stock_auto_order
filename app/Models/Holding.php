<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'symbol_code',
    'market',
    'instrument_type',
    'symbol_name',
    'sector_classification_id',
    'first_detected_at',
])]
class Holding extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_detected_at' => 'datetime',
        ];
    }

    public function holdingSnapshots(): HasMany
    {
        return $this->hasMany(HoldingSnapshot::class);
    }

    public function sectorClassification(): BelongsTo
    {
        return $this->belongsTo(SectorClassification::class);
    }

    public function technicalIndicator(): HasOne
    {
        return $this->hasOne(TechnicalIndicator::class);
    }

    public function fundamentalIndicator(): HasOne
    {
        return $this->hasOne(FundamentalIndicator::class);
    }
}
