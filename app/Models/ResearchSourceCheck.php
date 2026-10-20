<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchSourceCheck extends Model
{
    protected $fillable = ['source_key', 'trigger', 'started_at', 'finished_at', 'result', 'item_count', 'new_item_count', 'http_status', 'error_message', 'automation_payload', 'automation_cursor'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'item_count' => 'integer', 'new_item_count' => 'integer', 'http_status' => 'integer', 'automation_payload' => 'array', 'automation_cursor' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResearchSourceItem::class, 'first_check_id');
    }
}
