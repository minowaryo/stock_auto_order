<?php

namespace App\Actions\PriceTracking;

use App\Actions\PriceTracking\Support\PriceBackfillSummary;
use App\Models\Holding;
use App\Models\PriceTrackingTarget;
use App\Models\TradeExecution;
use App\Services\MarketData\PriceHistory;
use App\Services\PriceTracking\PriceFetchRunner;
use App\Services\PriceTracking\PriceTrackingRegistrar;
use Illuminate\Support\Facades\Log;

/**
 * One-time 10-year price backfill for every holding in the trade history,
 * plus the Nikkei 225, S&P 500 and USD/JPY series (UC-018, ADR-0024 D5).
 *
 * Rerunnable: a holding already backfilled or unavailable is skipped, so a
 * rerun only fetches what an earlier run did not finish. Pacing and the
 * stop on a streak of failures are PriceFetchRunner's.
 */
class BackfillPriceHistoryAction
{
    private const INDICES = ['nikkei225', 'sp500', 'usdjpy'];

    public function __construct(
        private readonly PriceFetchRunner $runner,
        private readonly PriceTrackingRegistrar $registrar,
    ) {}

    public function execute(bool $dryRun = false): PriceBackfillSummary
    {
        $holdings = Holding::query()
            ->whereIn('id', TradeExecution::query()->select('holding_id'))
            ->orderBy('id')
            ->get();

        $existing = PriceTrackingTarget::query()
            ->whereIn('holding_id', $holdings->modelKeys())
            ->get()
            ->keyBy('holding_id');

        $toFetch = $holdings->reject(fn (Holding $holding) => $this->done($existing->get($holding->id)));
        $skipped = $holdings->count() - $toFetch->count();

        if ($dryRun) {
            return new PriceBackfillSummary($holdings->count(), $skipped);
        }

        $this->registrar->register($holdings);
        $this->runner->start();

        $counts = ['ok' => 0, 'not_found' => 0, 'empty' => 0, 'failed' => 0, 'indices_ok' => 0, 'indices_failed' => 0];
        $aborted = false;

        foreach (self::INDICES as $indexName) {
            $status = $this->runner->fetchIndex($indexName);
            $counts[$status === PriceHistory::OK ? 'indices_ok' : 'indices_failed']++;

            if ($this->runner->shouldAbort($status)) {
                $aborted = true;
                break;
            }
        }

        if (! $aborted) {
            foreach ($toFetch as $holding) {
                $status = $this->runner->fetchHolding($holding);
                $counts[$status]++;

                if ($this->runner->shouldAbort($status)) {
                    $aborted = true;
                    break;
                }
            }
        }

        $summary = new PriceBackfillSummary(
            targets: $holdings->count(),
            skipped: $skipped,
            ok: $counts['ok'],
            notFound: $counts['not_found'],
            empty: $counts['empty'],
            failed: $counts['failed'],
            indicesOk: $counts['indices_ok'],
            indicesFailed: $counts['indices_failed'],
            aborted: $aborted,
        );

        Log::info('PriceBackfill: finished', (array) $summary);

        return $summary;
    }

    private function done(?PriceTrackingTarget $target): bool
    {
        return $target !== null && ($target->backfilled_from_week !== null || $target->status === 'unavailable');
    }
}
