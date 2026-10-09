<?php

namespace App\Actions\PriceTracking;

use App\Actions\PriceTracking\Support\PriceBackfillSummary;
use App\Models\Holding;
use App\Models\PriceTrackingTarget;
use App\Models\SignalOccurrence;
use App\Models\TradeExecution;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\PriceHistory;
use App\Services\PriceTracking\PriceTrackingTargetUpdater;
use App\Services\PriceTracking\StockSplitRecorder;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * One-time 10-year price backfill for every holding in the trade history,
 * plus the Nikkei 225, S&P 500 and USD/JPY series (UC-018, ADR-0024 D5).
 *
 * Rerunnable: a holding already backfilled or unavailable is skipped, so a
 * rerun only fetches what an earlier run did not finish. Requests are
 * paced, and the run stops after a streak of failed fetches, because Yahoo
 * has been seen answering every symbol with a bare 404 for a while
 * (2026-10-05); retrying through that would only burn requests.
 */
class BackfillPriceHistoryAction
{
    private const INDICES = ['nikkei225', 'sp500', 'usdjpy'];

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

        $this->register($holdings);

        $this->requests = 0;
        $this->failureStreak = 0;
        $counts = ['ok' => 0, 'not_found' => 0, 'empty' => 0, 'failed' => 0, 'indices_ok' => 0, 'indices_failed' => 0];
        $aborted = false;

        foreach (self::INDICES as $indexName) {
            $history = $this->request(fn () => $this->client->fetchIndex($indexName));

            if ($history->status === PriceHistory::OK) {
                $this->prices->recordIndex($indexName, $history->rows);
                $counts['indices_ok']++;
            } else {
                Log::warning('PriceBackfill: index fetch did not succeed', [
                    'index_name' => $indexName,
                    'status' => $history->status,
                    'message' => $history->message,
                ]);
                $counts['indices_failed']++;
            }

            if ($this->tooManyFailures($history->status)) {
                $aborted = true;
                break;
            }
        }

        if (! $aborted) {
            foreach ($toFetch as $holding) {
                $status = $this->backfillHolding($holding);
                $counts[$status]++;

                if ($this->tooManyFailures($status)) {
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

    /**
     * Registers / extends the tracking deadline of every target holding from
     * its last sale and its last signal week.
     *
     * @param  Collection<int, Holding>  $holdings
     */
    private function register(Collection $holdings): void
    {
        $ids = $holdings->modelKeys();

        $lastSells = TradeExecution::query()
            ->whereIn('holding_id', $ids)
            ->where('kind', 'sell')
            ->groupBy('holding_id')
            ->selectRaw('holding_id, MAX(trade_date) AS last_date') // aggregate only, no user input
            ->pluck('last_date', 'holding_id');

        $lastSignals = SignalOccurrence::query()
            ->whereIn('holding_id', $ids)
            ->groupBy('holding_id')
            ->selectRaw('holding_id, MAX(observed_week) AS last_week') // aggregate only, no user input
            ->pluck('last_week', 'holding_id');

        foreach ($holdings as $holding) {
            $this->targets->register($holding, $lastSells[$holding->id] ?? null, $lastSignals[$holding->id] ?? null);
        }
    }

    /**
     * @return 'ok'|'not_found'|'empty'|'failed'
     */
    private function backfillHolding(Holding $holding): string
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
            Log::warning('PriceBackfill: holding fetch did not succeed', [
                'holding_id' => $holding->id,
                'status' => $status,
                'message' => $target->last_error,
            ]);
        }

        return $status;
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

    private function tooManyFailures(string $status): bool
    {
        $this->failureStreak = $status === PriceHistory::FAILED ? $this->failureStreak + 1 : 0;

        return $this->failureStreak >= self::ABORT_AFTER;
    }
}
