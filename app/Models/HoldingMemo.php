<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['holding_id', 'body', 'recorded_at'])]
class HoldingMemo extends Model
{
    /**
     * Memos are append-only history rows (追記のみ・編集不可、UC-003業務ルール、
     * docs/architecture/data-model.md#holding_memos), so only recorded_at is
     * kept — no created_at/updated_at pair.
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
