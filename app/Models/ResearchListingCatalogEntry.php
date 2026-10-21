<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchListingCatalogEntry extends Model
{
    protected $fillable = ['import_id', 'market', 'symbol_code', 'issuer_key', 'legal_name', 'normalized_name', 'name_hash', 'exchange', 'security_type', 'reference_url'];

    protected function casts(): array
    {
        return ['import_id' => 'integer'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ResearchListingCatalogImport::class, 'import_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ResearchCompanyAlias::class, 'target_entry_id');
    }

    public function listingMatches(): HasMany
    {
        return $this->hasMany(ResearchDraftListingMatch::class, 'entry_id');
    }
}
