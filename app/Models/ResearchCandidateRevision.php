<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchCandidateRevision extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['research_candidate_id', 'lock_version', 'change_type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ResearchCandidate::class, 'research_candidate_id');
    }
}
