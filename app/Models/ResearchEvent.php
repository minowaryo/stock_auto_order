<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchEvent extends Model
{
    protected $fillable = ['title', 'original_url', 'original_publisher', 'announced_on', 'verification_status', 'verified_on', 'merged_into_event_id'];

    protected function casts(): array
    {
        return ['announced_on' => 'date', 'verified_on' => 'date'];
    }

    public function discoveryLinks(): HasMany
    {
        return $this->hasMany(ResearchDiscoveryLink::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ResearchCandidate::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_event_id');
    }
}
