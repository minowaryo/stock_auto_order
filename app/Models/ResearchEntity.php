<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchEntity extends Model
{
    protected $fillable = ['legal_name', 'identification_status', 'identification_note'];

    public function listings(): HasMany
    {
        return $this->hasMany(ResearchEntityListing::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ResearchCandidate::class);
    }
}
