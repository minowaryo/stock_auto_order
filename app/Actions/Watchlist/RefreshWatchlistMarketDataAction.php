<?php

namespace App\Actions\Watchlist;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\WatchlistBuySignal;
use App\Models\WatchlistItem;
use App\Models\WatchlistRefreshRun;
use App\Services\Analysis\BuySignalDeterminationService;
use App\Services\Analysis\FundamentalIndicatorMapper;
use App\Services\Analysis\TechnicalIndicatorCalculator;
use App\Services\Analysis\UsFundamentalIndicatorMapper;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * UC-012「基本フロー（一括更新）」(F-012 / ADR-0013 D5): refresh
 * technical / fundamental indicators and 押し目買い signals for the
 * *unheld* watchlist symbols, reusing the same external clients / mappers /
 * services as the weekly holdings pipeline (FetchExternalMarketDataAction).
 *
 * - Target = watchlist_items whose holding is NOT in the latest snapshot's
 *   holding_snapshots. Held favorites are already refreshed by the weekly
 *   CSV import, so we never double-fetch them.
 * - Writes technical_indicators / fundamental_indicators (holding_id UPSERT)
 *   and watchlist_buy_signals (per-holding replace). NEVER touches
 *   signals / buy_signals / snapshots / holding_snapshots.
 * - A per-symbol fetch failure is logged and skipped (failed_count++), the
 *   rest of the batch continues (same as FetchExternalMarketDataAction).
 * - Sector relative strength is not computed for watchlist symbols (there is
 *   no "portfolio sector average" for unheld candidates — same as how US
 *   holdings get a null relative_strength_vs_sector today).
 */
class RefreshWatchlistMarketDataAction
{
    /**
     * Minimum data points to compute a 13-week return (13 weeks back + now),
     * mirroring FetchExternalMarketDataAction::RETURN_WINDOW.
     */
    private const RETURN_WINDOW = 14;

    public function __construct(
        private readonly JpStockPriceClientInterface $jpStockPriceClient,
        private readonly UsStockPriceClientInterface $usStockPriceClient,
        private readonly MarketIndexClientInterface $marketIndexClient,
        private readonly JQuantsClientInterface $jQuantsClient,
        private readonly FinnhubClientInterface $finnhubClient,
        private readonly TechnicalIndicatorCalculator $technicalIndicatorCalculator,
        private readonly FundamentalIndicatorMapper $fundamentalIndicatorMapper,
        private readonly UsFundamentalIndicatorMapper $usFundamentalIndicatorMapper,
        private readonly BuySignalDeterminationService $buySignalDeterminationService,
    ) {}

    public function execute(): WatchlistRefreshRun
    {
        $run = WatchlistRefreshRun::create([
            'status' => WatchlistRefreshRun::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        $targets = $this->unheldWatchlistItems();
        $run->update(['total_count' => $targets->count()]);

        $nikkeiReturn13w = $this->calculate13wReturn($this->safeFetchIndex('nikkei225'));
        $sp500Return13w = $this->calculate13wReturn($this->safeFetchIndex('sp500'));

        foreach ($targets as $item) {
            try {
                $lastClose = $this->refreshHolding(
                    $item->holding,
                    $item->holding->market === 'jp' ? $nikkeiReturn13w : $sp500Return13w,
                );
                $item->update(['last_close' => $lastClose, 'last_refreshed_at' => now()]);
            } catch (Throwable $e) {
                // MarketData client exceptions carry only the request URL /
                // status, never the API key (same safety note as
                // FetchExternalMarketDataAction).
                Log::warning('RefreshWatchlistMarketDataAction: per-symbol refresh failed', [
                    'holding_id' => $item->holding_id,
                    'symbol_code' => $item->holding->symbol_code,
                    'exception' => $e->getMessage(),
                ]);
                $run->increment('failed_count');
            } finally {
                $run->increment('processed_count');
            }
        }

        $run->update([
            'status' => WatchlistRefreshRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * @return Collection<int, WatchlistItem>
     */
    private function unheldWatchlistItems()
    {
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        $heldHoldingIds = $latestSnapshot
            ? HoldingSnapshot::query()->where('snapshot_id', $latestSnapshot->id)->pluck('holding_id')->all()
            : [];

        return WatchlistItem::query()
            ->whereNotIn('holding_id', $heldHoldingIds)
            ->with('holding')
            ->get();
    }

    /**
     * @return float|null the latest weekly close (persisted as
     *                    watchlist_items.last_close for the screen's
     *                    "現在値" / 52週レンジ内位置 / 乖離チップ)
     */
    private function refreshHolding(Holding $holding, ?float $marketReturn13w): ?float
    {
        $priceHistory = $holding->market === 'jp'
            ? $this->jpStockPriceClient->fetchWeeklyPriceHistory($holding->symbol_code)
            : $this->usStockPriceClient->fetchWeeklyPriceHistory($holding->symbol_code);

        $technical = $this->technicalIndicatorCalculator->calculate($priceHistory, $marketReturn13w, null);

        TechnicalIndicator::updateOrCreate(
            ['holding_id' => $holding->id],
            [...$technical, 'computed_at' => now()],
        );

        $currentPrice = $priceHistory !== []
            ? (float) $priceHistory[count($priceHistory) - 1]['close']
            : null;

        if ($holding->market === 'jp') {
            $statements = $this->jQuantsClient->fetchStatements($holding->symbol_code);
            $fundamental = $this->fundamentalIndicatorMapper->map($statements, $currentPrice);
        } else {
            $metrics = $this->finnhubClient->fetchMetrics($holding->symbol_code) ?? [];
            $reportedFinancials = $this->finnhubClient->fetchReportedFinancials($holding->symbol_code);
            $fundamental = $this->usFundamentalIndicatorMapper->map($metrics, $reportedFinancials);
        }

        FundamentalIndicator::updateOrCreate(
            ['holding_id' => $holding->id],
            [...$fundamental, 'fetched_at' => now()],
        );

        $pegRatio = $fundamental['peg_ratio'] ?? null;

        $buySignals = $this->buySignalDeterminationService->determine(
            $priceHistory,
            $marketReturn13w,
            null,
            $pegRatio,
        );

        // Re-determination: drop this holding's previous rows before persisting
        // the freshly-determined set (same drop-then-recreate pattern as
        // FetchExternalMarketDataAction's buy_signals handling).
        WatchlistBuySignal::where('holding_id', $holding->id)->delete();

        foreach ($buySignals as $signal) {
            WatchlistBuySignal::create([
                'holding_id' => $holding->id,
                'signal_type' => $signal['signal_type'],
                'reason_summary' => $signal['reason_summary'],
                'determined_at' => now(),
            ]);
        }

        return $currentPrice;
    }

    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    private function safeFetchIndex(string $indexName): array
    {
        try {
            return $this->marketIndexClient->fetchWeeklyHistory($indexName);
        } catch (Throwable $e) {
            Log::warning('RefreshWatchlistMarketDataAction: market index fetch failed', [
                'index' => $indexName,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
     */
    private function calculate13wReturn(array $priceHistory): ?float
    {
        $count = count($priceHistory);

        if ($count < self::RETURN_WINDOW) {
            return null;
        }

        $current = (float) $priceHistory[$count - 1]['close'];
        $past = (float) $priceHistory[$count - self::RETURN_WINDOW]['close'];

        if ($past == 0.0) {
            return null;
        }

        return (($current - $past) / $past) * 100;
    }
}
