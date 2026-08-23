<?php

namespace App\Actions\Candidate;

use App\Models\Holding;
use App\Models\WatchRecord;

/**
 * UC-006 (新規投資候補の重複チェック): appends a new watch status/memo record
 * for a holding. Watch records are append-only — no edit/delete
 * (docs/architecture/data-model.md#watch_records).
 */
class SaveWatchRecordAction
{
    public function execute(Holding $holding, ?string $watchStatus, ?string $watchMemo): WatchRecord
    {
        return $holding->watchRecords()->create([
            'watch_status' => $watchStatus,
            'memo' => $watchMemo,
            'recorded_at' => now(),
        ]);
    }
}
