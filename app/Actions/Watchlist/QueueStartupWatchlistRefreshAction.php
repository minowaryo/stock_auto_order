<?php

namespace App\Actions\Watchlist;

use App\Jobs\RefreshWatchlistMarketDataJob;
use App\Models\WatchlistRefreshRun;
use Illuminate\Support\Carbon;
use Throwable;

/** UC-019: queue the watchlist refresh at startup, at most once per 72 hours. */
class QueueStartupWatchlistRefreshAction
{
    private const INTERVAL_HOURS = 72;

    public function execute(): ?WatchlistRefreshRun
    {
        if (WatchlistRefreshRun::active()->exists()) {
            return null;
        }

        $lastFinishedAt = WatchlistRefreshRun::query()
            ->where('status', WatchlistRefreshRun::STATUS_COMPLETED)
            ->max('finished_at');
        if ($lastFinishedAt !== null
            && now()->diffInHours(Carbon::parse($lastFinishedAt), true) < self::INTERVAL_HOURS
            && now()->lt(Carbon::parse($lastFinishedAt)->addHours(self::INTERVAL_HOURS))) {
            return null;
        }

        if (! RefreshWatchlistMarketDataAction::unheldWatchlistQuery()->exists()) {
            return null;
        }

        $run = WatchlistRefreshRun::create(['status' => WatchlistRefreshRun::STATUS_QUEUED]);
        try {
            RefreshWatchlistMarketDataJob::dispatch($run->id);
        } catch (Throwable $exception) {
            $run->update(['status' => WatchlistRefreshRun::STATUS_FAILED, 'finished_at' => now()]);
            throw $exception;
        }

        return $run;
    }
}
