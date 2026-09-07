<?php

namespace App\Jobs;

use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the UC-012 watchlist bulk refresh (F-012 / ADR-0013 D5) off the
 * request cycle. Dispatched by ImportFavoriteCsvAction after a successful
 * favorites-CSV import and by the "一括更新" button; the same work is also
 * available synchronously via the `watchlist:refresh` command.
 *
 * ~100–150 unheld symbols × a few external calls each, rate-limited by
 * Finnhub's free tier (60 req/min), so this takes minutes — hence the queue.
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

    public function handle(RefreshWatchlistMarketDataAction $action): void
    {
        $action->execute();
    }
}
