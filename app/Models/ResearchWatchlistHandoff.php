<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchWatchlistHandoff extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['research_candidate_id', 'research_candidate_revision_id', 'research_entity_listing_id', 'holding_id', 'watchlist_item_id', 'outcome', 'matched_snapshot_id', 'favorites_last_imported_at', 'idempotency_key'];

    protected function casts(): array
    {
        return ['favorites_last_imported_at' => 'datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ResearchCandidate::class, 'research_candidate_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ResearchEntityListing::class, 'research_entity_listing_id');
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }

    public function watchlistItem(): BelongsTo
    {
        return $this->belongsTo(WatchlistItem::class);
    }
}
