<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_summary_report_id',
    'rank',
    'is_supplementary',
    'recommendation_type',
    'target_label',
    'action_suggestion',
    'reason_summary',
    'link_to',
    'composite_score',
])]
class ImportSummaryReportItem extends Model
{
    /**
     * Recomputed/replaced on every UC-009 report fetch (no meaningful
     * created_at/updated_at semantics; docs/architecture/data-model.md has
     * no timestamp columns for this table).
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_supplementary' => 'boolean',
            'composite_score' => 'decimal:4',
        ];
    }

    public function importSummaryReport(): BelongsTo
    {
        return $this->belongsTo(ImportSummaryReport::class);
    }
}
