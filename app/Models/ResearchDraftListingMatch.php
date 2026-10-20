<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDraftListingMatch extends Model
{
    protected $fillable = ['draft_id', 'entry_id', 'generation', 'alias_id', 'match_method', 'reason', 'matched_at', 'superseded_at'];

    protected function casts(): array
    {
        return ['draft_id' => 'integer', 'entry_id' => 'integer', 'generation' => 'integer', 'alias_id' => 'integer', 'matched_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ResearchCandidateDraft::class, 'draft_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ResearchListingCatalogEntry::class, 'entry_id');
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(ResearchCompanyAlias::class, 'alias_id');
    }
}
