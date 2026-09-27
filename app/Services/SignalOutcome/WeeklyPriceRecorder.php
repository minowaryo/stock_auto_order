<?php

namespace App\Services\SignalOutcome;

use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\WeeklyPrice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists already-fetched weekly bars as weekly_prices /
 * index_weekly_prices (UC-014, ADR-0017 D2). Dates are normalized to the
 * Monday week start, the first row per week wins (later rows are Yahoo's
 * trailing last-trading-day bar), and rows are UPSERTed so retroactive
 * split adjustments overwrite existing weeks.
 *
 * Never throws: a recording failure must not stop the existing signal
 * determination (UC-014 エラーケース).
 */
class WeeklyPriceRecorder
{
    public function __construct(
        private readonly WeekDateNormalizer $weekDateNormalizer,
    ) {}

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $history
     */
    public function recordHolding(Holding $holding, array $history): void
    {
        try {
            $rows = [];

            foreach ($this->firstRowPerWeek($history) as $weekDate => $row) {
                $rows[] = [
                    'holding_id' => $holding->id,
                    'week_date' => $weekDate,
                    'close' => $row['close'],
                    'volume' => $row['volume'],
                ];
            }

            if ($rows === []) {
                return;
            }

            WeeklyPrice::upsert($rows, ['holding_id', 'week_date'], ['close', 'volume']);
        } catch (Throwable $e) {
            Log::warning('WeeklyPriceRecorder: holding weekly price recording failed', [
                'holding_id' => $holding->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $history
     */
    public function recordIndex(string $indexName, array $history): void
    {
        try {
            $rows = [];

            foreach ($this->firstRowPerWeek($history) as $weekDate => $row) {
                $rows[] = [
                    'index_name' => $indexName,
                    'week_date' => $weekDate,
                    'close' => $row['close'],
                ];
            }

            if ($rows === []) {
                return;
            }

            IndexWeeklyPrice::upsert($rows, ['index_name', 'week_date'], ['close']);
        } catch (Throwable $e) {
            Log::warning('WeeklyPriceRecorder: index weekly price recording failed', [
                'index_name' => $indexName,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $history
     * @return array<string, array{date: string, close: float, volume: int}>
     */
    private function firstRowPerWeek(array $history): array
    {
        $byWeek = [];

        foreach ($history as $row) {
            $weekDate = $this->weekDateNormalizer->weekStart($row['date']);
            $byWeek[$weekDate] ??= $row;
        }

        return $byWeek;
    }
}
