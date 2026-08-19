<?php

namespace App\Actions\Holding;

use App\Models\Holding;
use App\Models\HoldingMemo;

/**
 * UC-003 (銘柄詳細表示): appends a new memo entry for a holding. Memos are
 * append-only — no edit/delete (docs/architecture/data-model.md#holding_memos).
 */
class SaveHoldingMemoAction
{
    public function execute(Holding $holding, string $body): HoldingMemo
    {
        return $holding->memos()->create([
            'body' => $body,
            'recorded_at' => now(),
        ]);
    }
}
