<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDiscoveryLink extends Model
{
    protected $fillable = ['research_event_id', 'route_type', 'url', 'url_hash', 'source_title', 'publisher', 'posted_on', 'checked_on', 'summary', 'sponsorship_note', 'input_origin', 'generated_summary', 'source_document_version_id'];

    protected function casts(): array
    {
        return ['posted_on' => 'date', 'checked_on' => 'date', 'source_document_version_id' => 'integer'];
    }

    public function sourceDocumentVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'source_document_version_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ResearchEvent::class, 'research_event_id');
    }
}
