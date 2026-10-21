<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDraftRevision extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['draft_id', 'lock_version', 'change_type', 'actor_id', 'payload'];

    protected function casts(): array
    {
        return ['draft_id' => 'integer', 'lock_version' => 'integer', 'actor_id' => 'integer', 'payload' => 'array'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ResearchCandidateDraft::class, 'draft_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
