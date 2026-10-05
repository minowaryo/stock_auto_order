<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchClaim extends Model
{
    protected $fillable = ['research_candidate_id', 'claim', 'evidence_url', 'status', 'is_primary_source', 'checked_on'];

    protected function casts(): array
    {
        return ['is_primary_source' => 'boolean', 'checked_on' => 'date'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ResearchCandidate::class, 'research_candidate_id');
    }
}
