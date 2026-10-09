<?php

namespace App\Services\PriceTracking;

use App\Models\Holding;
use App\Models\StockSplit;
use App\Services\MarketData\PriceHistory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upserts the stock splits of a successful price fetch into stock_splits
 * (UC-018, ADR-0027 D6). Yahoo is the source of truth, so a corrected ratio
 * on the same date overwrites the old one; a split missing from a newer
 * response is never deleted.
 *
 * Never throws: a recording failure must not stop the caller. Only
 * holding_id is logged (no prices).
 */
class StockSplitRecorder
{
    public function record(Holding $holding, PriceHistory $history): void
    {
        if ($history->status !== PriceHistory::OK || $history->splits === []) {
            return;
        }

        try {
            $now = now();

            StockSplit::upsert(
                array_map(fn (array $split) => [
                    'holding_id' => $holding->id,
                    'effective_date' => $split['date'],
                    'ratio_numerator' => $split['numerator'],
                    'ratio_denominator' => $split['denominator'],
                    'source' => 'yahoo',
                    'created_at' => $now,
                ], $history->splits),
                ['holding_id', 'effective_date'],
                ['ratio_numerator', 'ratio_denominator', 'source'],
            );
        } catch (Throwable $e) {
            Log::warning('StockSplitRecorder: stock split recording failed', [
                'holding_id' => $holding->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
