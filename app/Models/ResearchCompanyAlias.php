<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchCompanyAlias extends Model
{
    protected $fillable = ['raw_name', 'normalized_name', 'name_hash', 'target_entry_id', 'relationship', 'evidence_url', 'note', 'confirmed_on', 'confirmed_by', 'revoked_at'];

    protected function casts(): array
    {
        return ['target_entry_id' => 'integer', 'confirmed_on' => 'date', 'confirmed_by' => 'integer', 'revoked_at' => 'immutable_datetime'];
    }

    public function targetEntry(): BelongsTo
    {
        return $this->belongsTo(ResearchListingCatalogEntry::class, 'target_entry_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function listingMatches(): HasMany
    {
        return $this->hasMany(ResearchDraftListingMatch::class, 'alias_id');
    }
}
