<?php

namespace App\Actions\Analysis;

use App\Models\FundamentalIndicator;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\MarketIndicatorSnapshot;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\FundamentalIndicatorMapper;
use App\Services\Analysis\SignalDeterminationService;
use App\Services\Analysis\TechnicalIndicatorCalculator;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the ADR-0004 analysis-engine expansion: fetches market-wide
 * indicators, per-holding price history / sector / fundamentals from
 * external market data sources, computes technical & fundamental
 * indicators, and determines take-profit signals for holdings whose
 * unrealized gain exceeds the UC-004 threshold.
 *
 * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md,
 * docs/architecture/data-model.md.
 */
class FetchExternalMarketDataAction
{
    /**
     * Moving-average period (weeks) used for
     * market_indicator_snapshots.ma_deviation — aligned with
     * TechnicalIndicatorCalculator's slow (26-week) MACD EMA period.
     */
    private const MARKET_INDEX_MA_PERIOD = 26;

    /**
     * Minimum data points required to compute a 13-week return
     * (13 weeks back + the current week).
     */
    private const RETURN_WINDOW = 14;

    /**
     * Sell-timing signal determination (UC-004) only runs for holdings
     * whose unrealized_gain_rate strictly exceeds this threshold (%).
     */
    private const SIGNAL_GAIN_RATE_THRESHOLD = 20.0;

    public function __construct(
        private readonly JpStockPriceClientInterface $jpStockPriceClient,
        private readonly UsStockPriceClientInterface $usStockPriceClient,
        private readonly MarketIndexClientInterface $marketIndexClient,
        private readonly JQuantsClientInterface $jQuantsClient,
        private readonly TechnicalIndicatorCalculator $technicalIndicatorCalculator,
        private readonly FundamentalIndicatorMapper $fundamentalIndicatorMapper,
        private readonly SignalDeterminationService $signalDeterminationService,
    ) {}

    public function execute(ImportBatch $batch): void
    {
        $snapshot = Snapshot::where('import_batch_id', $batch->id)->firstOrFail();

        // Step: market-wide indicators (UC-007). Not gated on the presence
        // of any eligible stock holding.
        $nikkeiHistory = $this->marketIndexClient->fetchWeeklyHistory('nikkei225');
        $sp500History = $this->marketIndexClient->fetchWeeklyHistory('sp500');

        $this->saveMarketIndicatorSnapshot($snapshot, 'nikkei225', $nikkeiHistory);
        $this->saveMarketIndicatorSnapshot($snapshot, 'sp500', $sp500History);

        $nikkeiReturn13w = $this->calculate13wReturn($nikkeiHistory);
        $sp500Return13w = $this->calculate13wReturn($sp500History);

        // Step: gather price history + resolve sector for every stock
        // holding recorded in this snapshot (ETF/investment trusts are out
        // of scope, UC-002業務ルール). A per-symbol fetch failure is caught
        // and that holding is skipped entirely so it never blocks the rest
        // of the batch.
        $eligible = [];

        $holdingSnapshots = HoldingSnapshot::where('snapshot_id', $snapshot->id)
            ->with('holding')
            ->get()
            ->filter(fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->holding->instrument_type === 'stock');

        foreach ($holdingSnapshots as $holdingSnapshot) {
            $holding = $holdingSnapshot->holding;

            try {
                $priceHistory = $holding->market === 'jp'
                    ? $this->jpStockPriceClient->fetchWeeklyPriceHistory($holding->symbol_code)
                    : $this->usStockPriceClient->fetchWeeklyPriceHistory($holding->symbol_code);

                $sectorClassificationId = $holding->sector_classification_id;

                if ($holding->market === 'jp') {
                    $sectorInfo = $this->jQuantsClient->fetchSectorInfo($holding->symbol_code);

                    if ($sectorInfo !== null) {
                        $sector = SectorClassification::firstOrCreate(
                            ['name' => $sectorInfo['name']],
                            ['code' => $sectorInfo['code']],
                        );

                        $sectorClassificationId = $sector->id;
                        $holding->forceFill(['sector_classification_id' => $sectorClassificationId])->save();
                    }
                }

                $eligible[] = [
                    'holding' => $holding,
                    'holdingSnapshot' => $holdingSnapshot,
                    'priceHistory' => $priceHistory,
                    'ownReturn13w' => $this->calculate13wReturn($priceHistory),
                    // Sector-average relative strength only applies to JP
                    // stocks (J-Quants無料プランに業種別指数がないための簡易算出).
                    'sectorClassificationId' => $holding->market === 'jp' ? $sectorClassificationId : null,
                ];
            } catch (Throwable $e) {
                Log::warning('FetchExternalMarketDataAction: holding price/sector fetch failed', [
                    'holding_id' => $holding->id,
                    'symbol_code' => $holding->symbol_code,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }
        }

        // Sector-average 13-week return, scoped to the stock holdings
        // gathered in this batch (docs/architecture/data-model.md
        // "保有銘柄内の同一セクター平均騰落率").
        $sectorReturnBuckets = [];

        foreach ($eligible as $row) {
            if ($row['sectorClassificationId'] === null || $row['ownReturn13w'] === null) {
                continue;
            }

            $sectorReturnBuckets[$row['sectorClassificationId']][] = $row['ownReturn13w'];
        }

        $sectorAverageReturns = array_map(
            static fn (array $returns): float => array_sum($returns) / count($returns),
            $sectorReturnBuckets,
        );

        foreach ($eligible as $row) {
            $holding = $row['holding'];
            $holdingSnapshot = $row['holdingSnapshot'];
            $priceHistory = $row['priceHistory'];

            try {
                DB::transaction(function () use ($holding, $holdingSnapshot, $priceHistory, $row, $nikkeiReturn13w, $sp500Return13w, $sectorAverageReturns) {
                    $marketReturn13w = $holding->market === 'jp' ? $nikkeiReturn13w : $sp500Return13w;
                    $sectorReturn13w = $row['sectorClassificationId'] !== null
                        ? ($sectorAverageReturns[$row['sectorClassificationId']] ?? null)
                        : null;

                    $technical = $this->technicalIndicatorCalculator->calculate($priceHistory, $marketReturn13w, $sectorReturn13w);

                    TechnicalIndicator::updateOrCreate(
                        ['holding_id' => $holding->id],
                        [...$technical, 'computed_at' => now()],
                    );

                    $pegRatio = null;

                    // Fundamentals/sector are JP個別株限定 (UC-002業務ルール
                    // "指標計算はJP株・US株の個別株のみ対象" + fundamentals自体はJP限定).
                    if ($holding->market === 'jp') {
                        $statements = $this->jQuantsClient->fetchStatements($holding->symbol_code);
                        $currentPrice = $holdingSnapshot->current_price !== null
                            ? (float) $holdingSnapshot->current_price
                            : null;

                        $fundamental = $this->fundamentalIndicatorMapper->map($statements, $currentPrice);

                        FundamentalIndicator::updateOrCreate(
                            ['holding_id' => $holding->id],
                            [...$fundamental, 'fetched_at' => now()],
                        );

                        $pegRatio = $fundamental['peg_ratio'];
                    }

                    // UC-004業務ルール: 含み益+20%未満は利確シグナル判定の対象外.
                    if ((float) $holdingSnapshot->unrealized_gain_rate > self::SIGNAL_GAIN_RATE_THRESHOLD) {
                        $signals = $this->signalDeterminationService->determine(
                            $priceHistory,
                            $marketReturn13w,
                            $sectorReturn13w,
                            $pegRatio,
                        );

                        // Re-determination: drop stale signal rows from a previous
                        // run before persisting the freshly-determined set, so
                        // signals that no longer hold true (e.g. price history
                        // replaced on retry) don't linger.
                        Signal::where('holding_snapshot_id', $holdingSnapshot->id)->delete();

                        foreach ($signals as $signal) {
                            Signal::create([
                                'holding_snapshot_id' => $holdingSnapshot->id,
                                'signal_type' => $signal['signal_type'],
                                'reason_summary' => $signal['reason_summary'],
                            ]);
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::warning('FetchExternalMarketDataAction: holding indicator/signal processing failed', [
                    'holding_id' => $holding->id,
                    'symbol_code' => $holding->symbol_code,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }
        }
    }

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $history
     */
    private function saveMarketIndicatorSnapshot(Snapshot $snapshot, string $indexName, array $history): void
    {
        $count = count($history);

        if ($count === 0) {
            return;
        }

        $closes = array_map(static fn (array $row): float => (float) $row['close'], $history);

        $value = $closes[$count - 1];
        $changeRate = null;

        if ($count >= 2) {
            $previousClose = $closes[$count - 2];
            $changeRate = $previousClose != 0.0 ? (($value - $previousClose) / $previousClose) * 100 : null;
        }

        $maDeviation = null;

        if ($count >= self::MARKET_INDEX_MA_PERIOD) {
            $movingAverage = array_sum(array_slice($closes, -self::MARKET_INDEX_MA_PERIOD)) / self::MARKET_INDEX_MA_PERIOD;
            $maDeviation = $movingAverage != 0.0 ? (($value - $movingAverage) / $movingAverage) * 100 : null;
        }

        MarketIndicatorSnapshot::updateOrCreate(
            ['snapshot_id' => $snapshot->id, 'index_name' => $indexName],
            ['value' => $value, 'change_rate' => $changeRate, 'ma_deviation' => $maDeviation],
        );
    }

    /**
     * Own 13-week return (%): (last close − close 13 weeks before) ÷ close
     * 13 weeks before × 100. Mirrors
     * TechnicalIndicatorCalculator::calculateRelativeStrength()'s
     * stock-return leg, applied here to both market indices and individual
     * holdings' own price history.
     *
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
