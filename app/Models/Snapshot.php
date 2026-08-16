<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['import_batch_id', 'snapshotted_at'])]
class Snapshot extends Model
{
    /**
     * Snapshots are append-only history rows (docs/architecture/data-model.md).
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshotted_at' => 'datetime',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function holdingSnapshots(): HasMany
    {
        return $this->hasMany(HoldingSnapshot::class);
    }
}
