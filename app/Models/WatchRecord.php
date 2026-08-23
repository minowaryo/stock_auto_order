<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['holding_id', 'watch_status', 'memo', 'recorded_at'])]
class WatchRecord extends Model
{
    /**
     * Watch records are append-only history rows (追記のみ・編集不可、UC-006業務
     * ルール、docs/architecture/data-model.md#watch_records), so only
     * recorded_at is kept — no created_at/updated_at pair.
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
            'recorded_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
