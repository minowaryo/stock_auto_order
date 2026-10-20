<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchListingCatalogImport extends Model
{
    protected $fillable = ['run_id', 'catalog_key', 'source_url', 'started_at', 'finished_at', 'published_at', 'as_of_on', 'status', 'content_hash', 'row_count', 'error_code'];

    protected function casts(): array
    {
        return ['run_id' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'as_of_on' => 'date', 'row_count' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchAutomationRun::class, 'run_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ResearchListingCatalogEntry::class, 'import_id');
    }
}
