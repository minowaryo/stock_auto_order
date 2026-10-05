<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchEntityListing extends Model
{
    protected $fillable = ['research_entity_id', 'market', 'symbol_code', 'listed_entity_name', 'source_url', 'confirmed_on'];

    protected function casts(): array
    {
        return ['confirmed_on' => 'date'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(ResearchEntity::class, 'research_entity_id');
    }
}
