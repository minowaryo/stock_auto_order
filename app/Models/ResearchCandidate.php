<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchCandidate extends Model
{
    protected $fillable = ['research_event_id', 'research_entity_id', 'theme', 'theme_relation', 'entity_role', 'evidence_stage', 'counter_evidence', 'counter_evidence_checked_on', 'status', 'status_reason', 'needs_recheck', 'lock_version', 'archived_at'];

    protected function casts(): array
    {
        return ['counter_evidence_checked_on' => 'date', 'needs_recheck' => 'boolean', 'archived_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ResearchEvent::class, 'research_event_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(ResearchEntity::class, 'research_entity_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ResearchClaim::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ResearchCandidateRevision::class);
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(ResearchWatchlistHandoff::class);
    }
}
