<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchCandidateDraft extends Model
{
    protected $fillable = ['document_id', 'latest_version_id', 'current_document_version_id', 'mention_key', 'raw_name', 'normalized_name', 'extracted_fields', 'human_overrides', 'disposition', 'reason', 'presence', 'first_detected_at', 'last_observed_at', 'discovery_kind', 'reviewed_version_id', 'reviewed_on', 'reviewed_by', 'imported_candidate_id', 'imported_link_id', 'imported_version_id', 'lock_version', 'match_generation', 'match_input_hash'];

    protected function casts(): array
    {
        return ['document_id' => 'integer', 'latest_version_id' => 'integer', 'current_document_version_id' => 'integer', 'extracted_fields' => 'array', 'human_overrides' => 'array', 'first_detected_at' => 'immutable_datetime', 'last_observed_at' => 'immutable_datetime', 'reviewed_version_id' => 'integer', 'reviewed_on' => 'date', 'reviewed_by' => 'integer', 'imported_candidate_id' => 'integer', 'imported_link_id' => 'integer', 'imported_version_id' => 'integer', 'lock_version' => 'integer', 'match_generation' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceDocument::class, 'document_id');
    }

    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'latest_version_id');
    }

    public function currentDocumentVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'current_document_version_id');
    }

    public function reviewedVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'reviewed_version_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function importedCandidate(): BelongsTo
    {
        return $this->belongsTo(ResearchCandidate::class, 'imported_candidate_id');
    }

    public function importedLink(): BelongsTo
    {
        return $this->belongsTo(ResearchDiscoveryLink::class, 'imported_link_id');
    }

    public function importedVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'imported_version_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ResearchDraftRevision::class, 'draft_id');
    }

    public function listingMatches(): HasMany
    {
        return $this->hasMany(ResearchDraftListingMatch::class, 'draft_id');
    }
}
