<?php

namespace App\Services\PriceTracking;

use App\Models\Holding;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\PriceHistory;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * One run of paced Yahoo fetches that are saved and applied to the tracking
 * state (UC-018, ADR-0024 D2・D5), shared by the one-time backfill and the
 * weekly tracking so both pace, log and stop the same way.
 *
 * Requests are paced, and the run should stop after a streak of failed
 * fetches (see shouldAbort()), because Yahoo has been seen answering every
 * symbol with a bare 404 for a while (2026-10-05); retrying through that
 * would only burn requests. Stateful: call start() at the beginning of a run.
 */
class PriceFetchRunner
{
    /** Shared by price:track and price:backfill so only one of them fetches at a time. */
    public const LOCK = 'price-fetch';

    /** A crashed run must not block later starts for long; a full backfill takes ~5 minutes. */
    public const LOCK_SECONDS = 1800;

    private const PAUSE_MS = 1500;

    /** Stop after this many 'failed' results in a row (an answer from Yahoo resets it). */
    private const ABORT_AFTER = 5;

    private int $requests = 0;

    private int $failureStreak = 0;

    public function __construct(
        private readonly PriceBackfillClientInterface $client,
        private readonly WeeklyPriceRecorder $prices,
        private readonly StockSplitRecorder $splits,
        private readonly PriceTrackingTargetUpdater $targets,
    ) {}

    public function start(): void
    {
        $this->requests = 0;
        $this->failureStreak = 0;
    }

    /**
     * Fetches and saves one index / USD/JPY series (no state row).
     *
     * @return 'ok'|'not_found'|'empty'|'failed'
     */
    public function fetchIndex(string $indexName): string
    {
        $history = $this->request(fn () => $this->client->fetchIndex($indexName));

        if ($history->status === PriceHistory::OK) {
            $this->prices->recordIndex($indexName, $history->rows);
        } else {
            Log::warning('PriceFetch: index fetch did not succeed', [
                'index_name' => $indexName,
                'status' => $history->status,
                'message' => $history->message,
            ]);
        }

        return $history->status;
    }

    /**
     * Fetches 10 years + splits for a holding, saves them and applies the
     * outcome to its tracking state.
     *
     * @return 'ok'|'not_found'|'empty'|'failed'
     */
    public function fetchHolding(Holding $holding): string
    {
        $history = $this->request(fn () => $this->client->fetchStock($holding->market, $holding->symbol_code));

        if ($history->status === PriceHistory::OK) {
            $this->prices->recordHolding($holding, $history->rows);
            $this->splits->record($holding, $history);
        }

        $target = $this->targets->applyFetch($holding, $history, backfill: true);

        // An ok fetch whose rows could not be verified as saved counts as failed.
        $status = $history->status === PriceHistory::OK && $target->last_error !== null
            ? PriceHistory::FAILED
            : $history->status;

        if ($status !== PriceHistory::OK) {
            Log::warning('PriceFetch: holding fetch did not succeed', [
                'holding_id' => $holding->id,
                'status' => $status,
                'message' => $target->last_error,
            ]);
        }

        return $status;
    }

    /**
     * Records the status of the latest fetch; true when the run should stop.
     */
    public function shouldAbort(string $status): bool
    {
        $this->failureStreak = $status === PriceHistory::FAILED ? $this->failureStreak + 1 : 0;

        return $this->failureStreak >= self::ABORT_AFTER;
    }

    /**
     * @param  callable(): PriceHistory  $fetch
     */
    private function request(callable $fetch): PriceHistory
    {
        if ($this->requests++ > 0) {
            Sleep::for(self::PAUSE_MS)->milliseconds();
        }

        return $fetch();
    }
}
