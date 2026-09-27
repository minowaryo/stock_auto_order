<?php

namespace App\Console\Commands;

use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\SignalOccurrence;
use App\Models\Snapshot;
use App\Models\WatchlistBuySignal;
use App\Services\SignalOutcome\SignalOccurrenceRecorder;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * UC-014 / ADR-0017 D6: one-time migration of the signals already stored
 * before signal_occurrences existed.
 *
 * - every snapshot's signals → take_profit, buy_signals → buy
 *   (snapshot_id set)
 * - current watchlist_buy_signals → watchlist_buy (snapshot_id null)
 * - observed_week = ISO-week Monday of snapshots.created_at /
 *   determined_at converted to Asia/Manila (the user's date-judgment
 *   timezone; the app timezone is UTC)
 * - metrics = null (根拠値不明). Insert-or-ignore, so occurrences already
 *   recorded live (with metrics) are never overwritten and re-running is a
 *   no-op.
 */
class BackfillSignalOccurrencesCommand extends Command
{
    private const OBSERVED_WEEK_TIMEZONE = 'Asia/Manila';

    protected $signature = 'signal-outcomes:backfill';

    protected $description = '既存スナップショットの利確・買い増しシグナルと現在のウォッチリスト押し目買いシグナルをシグナル発生記録（signal_occurrences）へ移送する';

    public function handle(SignalOccurrenceRecorder $recorder): int
    {
        $before = SignalOccurrence::count();
        $snapshotCount = 0;
        $watchlistSignalCount = 0;

        Snapshot::query()
            ->with(['holdingSnapshots.holding', 'holdingSnapshots.signals', 'holdingSnapshots.buySignals'])
            ->orderBy('id')
            ->chunkById(10, function (Collection $snapshots) use ($recorder, &$snapshotCount) {
                foreach ($snapshots as $snapshot) {
                    $snapshotCount++;
                    $observedWeek = $this->observedWeek($snapshot->created_at);

                    /** @var HoldingSnapshot $holdingSnapshot */
                    foreach ($snapshot->holdingSnapshots as $holdingSnapshot) {
                        $recorder->record($holdingSnapshot->holding, 'take_profit', $holdingSnapshot->signals->pluck('signal_type')->all(), $observedWeek, $snapshot->id, null);
                        $recorder->record($holdingSnapshot->holding, 'buy', $holdingSnapshot->buySignals->pluck('signal_type')->all(), $observedWeek, $snapshot->id, null);
                    }
                }
            });

        WatchlistBuySignal::query()
            ->with('holding')
            ->get()
            ->groupBy(fn (WatchlistBuySignal $signal) => $signal->holding_id.'|'.$this->observedWeek($signal->determined_at))
            ->each(function ($signals) use ($recorder, &$watchlistSignalCount) {
                $watchlistSignalCount += $signals->count();
                /** @var WatchlistBuySignal $first */
                $first = $signals->first();
                /** @var Holding $holding */
                $holding = $first->holding;

                $recorder->record($holding, 'watchlist_buy', $signals->pluck('signal_type')->all(), $this->observedWeek($first->determined_at), null, null);
            });

        $added = SignalOccurrence::count() - $before;

        $this->info("移送完了: スナップショット {$snapshotCount}件・ウォッチリスト押し目買いシグナル {$watchlistSignalCount}件を処理し、シグナル発生記録を {$added}件追加しました。");

        return self::SUCCESS;
    }

    private function observedWeek(CarbonInterface $timestamp): string
    {
        return $timestamp->copy()
            ->setTimezone(self::OBSERVED_WEEK_TIMEZONE)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->toDateString();
    }
}
