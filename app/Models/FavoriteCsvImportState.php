<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['latest_batch_id'])]
class FavoriteCsvImportState extends Model
{
    public $incrementing = false;

    protected function casts(): array
    {
        return ['latest_batch_id' => 'integer'];
    }

    public function latestBatch(): BelongsTo
    {
        return $this->belongsTo(FavoriteCsvImportBatch::class, 'latest_batch_id');
    }
}
