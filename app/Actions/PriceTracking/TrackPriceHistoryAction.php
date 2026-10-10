<?php

namespace App\Actions\PriceTracking;

use App\Actions\PriceTracking\Support\PriceTrackingSummary;
use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\PriceTrackingTarget;
use App\Models\SignalOccurrence;
use App\Models\TradeExecution;
use App\Services\MarketData\PriceHistory;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\PriceTracking\PriceFetchRunner;
use App\Services\PriceTracking\PriceTrackingRegistrar;
use App\Services\PriceTracking\PriceTrackingTargetUpdater;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly price tracking of sold holdings and recent signal holdings until
 * 26 weeks after the later of the last sale and the last signal
 * (UC-018, ADR-0024 D1・D2).
 *
 * Unlike ADR-0024 D2's 104 weeks, a tracked holding is fetched like the
 * backfill (10 years + splits): Yahoo adjusts every past close when a split
 * happens, so only a full refetch keeps the weeks before the 104-week
 * window consistent. A holding the holdings / watchlist refresh already
 * saved past its last confirmed week is not requested again.
 */
class TrackPriceHistoryAction
{
    private const INDICES = ['nikkei225', 'sp500', 'usdjpy'];

    private const SIGNAL_WEEKS = 26;

    public function __construct(
        private readonly PriceFetchRunner $runner,
        private readonly PriceTrackingRegistrar $registrar,
        private readonly PriceTrackingTargetUpdater $targets,
        private readonly WeekDateNormalizer $weeks,
    ) {}

    public function execute(bool $dryRun = false, bool $includeUnavailable = false): PriceTrackingSummary
    {
        if ($dryRun) {
            // Count with the deadlines a real run would register, then undo them.
            DB::beginTransaction();

            try {
                $this->registrar->register($this->candidates());

                return new PriceTrackingSummary($this->targetsToTrack($includeUnavailable)->count());
            } finally {
                DB::rollBack();
            }
        }

        $this->registrar->register($this->candidates());
        $targets = $this->targetsToTrack($includeUnavailable);
        $this->runner->start();

        $counts = ['saved_already' => 0, 'ok' => 0, 'not_found' => 0, 'empty' => 0, 'failed' => 0, 'completed' => 0, 'indices_ok' => 0, 'indices_failed' => 0];
        $aborted = false;

        foreach (self::INDICES as $indexName) {
            if ($this->indexIsCurrent($indexName)) {
                continue;
            }

            $status = $this->runner->fetchIndex($indexName);
            $counts[$status === PriceHistory::OK ? 'indices_ok' : 'indices_failed']++;

            if ($this->runner->shouldAbort($status)) {
                $aborted = true;
                break;
            }
        }

        if (! $aborted) {
            foreach ($targets as $target) {
                $saved = $this->targets->applySavedPrices($target->holding);

                if ($saved !== null) {
                    $counts['saved_already']++;
                    $counts['completed'] += $saved->status === 'completed' ? 1 : 0;

                    continue;
                }

                $status = $this->runner->fetchHolding($target->holding);
                $counts[$status]++;
                $counts['completed'] += $target->fresh()->status === 'completed' ? 1 : 0;

                if ($this->runner->shouldAbort($status)) {
                    $aborted = true;
                    break;
                }
            }
        }

        $summary = new PriceTrackingSummary(
            targets: $targets->count(),
            savedAlready: $counts['saved_already'],
            ok: $counts['ok'],
            notFound: $counts['not_found'],
            empty: $counts['empty'],
            failed: $counts['failed'],
            completed: $counts['completed'],
            indicesOk: $counts['indices_ok'],
            indicesFailed: $counts['indices_failed'],
            aborted: $aborted,
        );

        Log::info('PriceTracking: finished', (array) $summary);

        return $summary;
    }

    /**
     * Whether the index's last confirmed week was saved after that week was
     * confirmed (the rule of PriceTrackingTargetUpdater::applySavedPrices()),
     * so another start on the same day does not request it again.
     */
    private function indexIsCurrent(string $indexName): bool
    {
        $lastConfirmed = $this->weeks->weekStart(now()->subDays(7)->toDateString());

        $row = IndexWeeklyPrice::query()
            ->where('index_name', $indexName)
            ->where('week_date', $lastConfirmed)
            ->first(['updated_at']);

        return $row?->updated_at !== null
            && $row->updated_at->gte(Carbon::parse($lastConfirmed)->addDays(7)->startOfDay());
    }

    /**
     * Holdings that have a sale in the trade history or a signal in the
     * last 26 weeks.
     *
     * @return Collection<int, Holding>
     */
    private function candidates(): Collection
    {
        $since = now()->subWeeks(self::SIGNAL_WEEKS)->toDateString();

        return Holding::query()
            ->whereIn('id', TradeExecution::query()->where('kind', 'sell')->select('holding_id'))
            ->orWhereIn('id', SignalOccurrence::query()->where('observed_week', '>=', $since)->select('holding_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PriceTrackingTarget>
     */
    private function targetsToTrack(bool $includeUnavailable): Collection
    {
        return PriceTrackingTarget::query()
            ->with('holding')
            ->whereIn('status', $includeUnavailable ? ['active', 'unavailable'] : ['active'])
            ->whereNotNull('track_until_week')
            ->orderBy('holding_id')
            ->get();
    }
}
