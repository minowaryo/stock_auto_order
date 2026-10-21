<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDocumentAttempt extends Model
{
    protected $fillable = ['run_id', 'document_id', 'phase', 'started_at', 'finished_at', 'status', 'input_version_id', 'output_version_id', 'http_status', 'response_bytes', 'error_code'];

    protected function casts(): array
    {
        return ['run_id' => 'integer', 'document_id' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'input_version_id' => 'integer', 'output_version_id' => 'integer', 'http_status' => 'integer', 'response_bytes' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchAutomationRun::class, 'run_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ResearchSourceDocument::class, 'document_id');
    }

    public function inputVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'input_version_id');
    }

    public function outputVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchDocumentVersion::class, 'output_version_id');
    }
}
