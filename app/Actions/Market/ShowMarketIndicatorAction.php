<?php

namespace App\Actions\Market;

use App\Models\MarketIndicatorSnapshot;
use App\Models\Snapshot;

/**
 * UC-007 (市場全体指標表示): returns the latest snapshot's market indicator
 * rows (nikkei225/sp500/us10y/vix/usdjpy), always as a fixed-order 5-row
 * list. us10y/vix/usdjpy have no fetch/save logic anywhere in this codebase
 * yet, so they are always returned as null placeholders (see
 * tests/Feature/UC007MarketIndicatorTest.php header comment for the
 * confirmed scope of this cycle).
 */
class ShowMarketIndicatorAction
{
    private const INDEX_NAMES = ['nikkei225', 'sp500', 'us10y', 'vix', 'usdjpy'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        $indicators = $latestSnapshot
            ? MarketIndicatorSnapshot::query()
                ->where('snapshot_id', $latestSnapshot->id)
                ->get()
                ->keyBy('index_name')
            : collect();

        return collect(self::INDEX_NAMES)
            ->map(function (string $indexName) use ($indicators) {
                $row = $indicators->get($indexName);

                return [
                    'index_name' => $indexName,
                    'value' => $row?->value,
                    'change_rate' => $row?->change_rate,
                    'ma_deviation' => $row?->ma_deviation,
                ];
            })
            ->all();
    }
}
