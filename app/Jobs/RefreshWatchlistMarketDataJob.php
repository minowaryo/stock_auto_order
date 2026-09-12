<?php

namespace App\Jobs;

use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\WatchlistRefreshRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the UC-012 watchlist bulk refresh (F-012 / ADR-0013 D5) off the
 * request cycle. Dispatched by ImportFavoriteCsvAction after a successful
 * favorites-CSV import and by CandidateCheck::refreshAll(); the same work is
 * also available synchronously via the `watchlist:refresh` command.
 *
 * ~100–150 unheld symbols × a few external calls each, rate-limited by
 * Finnhub's free tier (60 req/min), so this takes minutes — hence the queue.
 *
 * The caller creates a `queued` WatchlistRefreshRun and passes its id here so
 * one row owns the whole lifecycle (queued → processing → completed/failed)
 * and WatchlistRefreshRun::active() is never left stuck (review #1/#3).
 */
class RefreshWatchlistMarketDataJob implements ShouldQueue
{
    use Queueable;

    /**
     * A single long-running attempt; a mid-run failure is recoverable by
     * pressing 一括更新 / running the command again (per-symbol failures are
     * already swallowed inside the action).
     */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly ?int $runId = null) {}

    public function handle(RefreshWatchlistMarketDataAction $action): void
    {
        $action->execute($this->runId !== null ? WatchlistRefreshRun::find($this->runId) : null);
    }

    /**
     * If the job itself fails (exhausted retries / worker killed / an
     * exception the action re-threw), make sure the run does not stay
     * `processing` forever — otherwise active() blocks every future refresh.
     */
    public function failed(?Throwable $e): void
    {
        if ($this->runId === null) {
            return;
        }

        WatchlistRefreshRun::where('id', $this->runId)
            ->whereIn('status', [WatchlistRefreshRun::STATUS_QUEUED, WatchlistRefreshRun::STATUS_PROCESSING])
            ->update(['status' => WatchlistRefreshRun::STATUS_FAILED, 'finished_at' => now()]);
    }
}
