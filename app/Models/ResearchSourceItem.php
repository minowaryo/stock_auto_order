<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchSourceItem extends Model
{
    protected $fillable = ['source_key', 'url', 'url_hash', 'title', 'published_at', 'metadata', 'first_check_id', 'is_baseline', 'disposition', 'research_discovery_link_id', 'reviewed_at'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'metadata' => 'array', 'is_baseline' => 'boolean'];
    }

    public function firstCheck(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceCheck::class, 'first_check_id');
    }

    public function discoveryLink(): BelongsTo
    {
        return $this->belongsTo(ResearchDiscoveryLink::class, 'research_discovery_link_id');
    }
}
