<?php

namespace App\Services\SignalOutcome;

use App\Models\Holding;
use App\Models\SignalOccurrence;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appends fired signals to signal_occurrences (UC-014, ADR-0017 D3).
 * One row per signal type; a row already recorded for the same
 * (holding, source, signal_type, observed_week) is kept as-is (the first
 * record and its metrics win — insert-or-ignore on the unique key).
 *
 * Never throws: a recording failure must not stop the existing signal
 * determination (UC-014 エラーケース). Metrics are never logged.
 */
class SignalOccurrenceRecorder
{
    /**
     * @param  'take_profit'|'buy'|'watchlist_buy'  $source
     * @param  array<int, string>  $signalTypes
     * @param  string  $observedWeek  Monday week start (Y-m-d)
     * @param  array<string, mixed>|null  $metrics
     */
    public function record(Holding $holding, string $source, array $signalTypes, string $observedWeek, ?int $snapshotId, ?array $metrics): void
    {
        if ($signalTypes === []) {
            return;
        }

        try {
            $encodedMetrics = $metrics === null ? null : json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $now = now();
            $rows = [];

            foreach (array_unique($signalTypes) as $signalType) {
                $rows[] = [
                    'holding_id' => $holding->id,
                    'source' => $source,
                    'signal_type' => $signalType,
                    'observed_week' => $observedWeek,
                    'snapshot_id' => $snapshotId,
                    'metrics' => $encodedMetrics,
                    'created_at' => $now,
                ];
            }

            SignalOccurrence::insertOrIgnore($rows);
        } catch (Throwable $e) {
            Log::warning('SignalOccurrenceRecorder: signal occurrence recording failed', [
                'holding_id' => $holding->id,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
