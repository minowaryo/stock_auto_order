<?php

namespace App\Jobs;

use App\Actions\TradeReview\ShowTradeReviewAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds the 売買の振り返り calculation (UC-018) in the background after
 * the data it reads changed, so opening the screen never waits for it
 * (CHG-0033 7e, 2026-10-11). Only one waits in the queue at a time: several
 * changes in a row cost one rebuild.
 */
class WarmTradeReviewCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    /**
     * Best effort: a failure only means the screen rebuilds when opened, so
     * it is logged and never reaches the import or command that queued it
     * (with a sync queue the job runs inside that flow).
     */
    public function handle(ShowTradeReviewAction $action): void
    {
        try {
            $action->execute();
        } catch (Throwable $e) {
            Log::warning('WarmTradeReviewCache: rebuilding the trade review failed', ['exception' => $e->getMessage()]);
        }
    }
}
