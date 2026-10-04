<?php

namespace App\Services\SignalOutcome;

use App\Models\Holding;
use App\Models\IndicatorObservation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Appends the indicators the weekly analysis obtained to
 * indicator_observations (UC-018, ADR-0027 D4). One row per call; rows are
 * never updated or deduplicated (observed_at tells them apart).
 *
 * Never throws: a recording failure must not stop the existing signal
 * determination. Only holding_id and source are logged; metrics never are.
 */
class IndicatorObservationRecorder
{
    /**
     * @param  'holding_import'|'watchlist_refresh'  $source
     * @param  array<string, mixed>  $metrics
     */
    public function record(Holding $holding, string $source, array $metrics): void
    {
        try {
            IndicatorObservation::create([
                'holding_id' => $holding->id,
                'source' => $source,
                'observed_at' => now(),
                'metrics' => $metrics,
            ]);
        } catch (Throwable $e) {
            Log::warning('IndicatorObservationRecorder: indicator observation recording failed', [
                'holding_id' => $holding->id,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
