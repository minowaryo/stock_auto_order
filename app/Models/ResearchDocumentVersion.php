<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDocumentVersion extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['document_id', 'phase', 'version_no', 'parent_feed_version_id', 'observed_check_id', 'attempt_id', 'payload', 'payload_hash', 'parser_version', 'rule_version', 'observed_at'];

    protected function casts(): array
    {
        return ['document_id' => 'integer', 'version_no' => 'integer', 'parent_feed_version_id' => 'integer', 'observed_check_id' => 'integer', 'attempt_id' => 'integer', 'payload' => 'array', 'observed_at' => 'immutable_datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceDocument::class, 'document_id');
    }

    public function parentFeedVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'parent_feed_version_id');
    }

    public function observedCheck(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceCheck::class, 'observed_check_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentAttempt::class, 'attempt_id');
    }
}
