<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchSourceDocument extends Model
{
    protected $fillable = ['source_item_id', 'latest_feed_version_id', 'latest_content_version_id', 'last_seen_check_id', 'extraction_state', 'last_attempt_at', 'last_success_at', 'next_attempt_at', 'extracted_version_id', 'lock_version'];

    protected function casts(): array
    {
        return ['source_item_id' => 'integer', 'latest_feed_version_id' => 'integer', 'latest_content_version_id' => 'integer', 'last_seen_check_id' => 'integer', 'last_attempt_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'extracted_version_id' => 'integer', 'lock_version' => 'integer'];
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceItem::class, 'source_item_id');
    }

    public function latestFeedVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'latest_feed_version_id');
    }

    public function latestContentVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'latest_content_version_id');
    }

    public function lastSeenCheck(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceCheck::class, 'last_seen_check_id');
    }

    public function extractedVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'extracted_version_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ResearchDocumentAttempt::class, 'document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ResearchDocumentVersion::class, 'document_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ResearchCandidateDraft::class, 'document_id');
    }
}
