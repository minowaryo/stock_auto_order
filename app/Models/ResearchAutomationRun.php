<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchAutomationRun extends Model
{
    protected $fillable = ['trigger', 'actor_id', 'started_at', 'finished_at', 'status', 'counters', 'error_code'];

    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'counters' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ResearchDocumentAttempt::class, 'run_id');
    }

    public function catalogImports(): HasMany
    {
        return $this->hasMany(ResearchListingCatalogImport::class, 'run_id');
    }
}
