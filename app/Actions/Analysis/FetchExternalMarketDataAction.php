<?php

namespace App\Actions\Analysis;

use App\Models\BuySignal;
use App\Models\FinancialStatement;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\MarketIndicatorSnapshot;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\BuySignalDeterminationService;
use App\Services\Analysis\FundamentalIndicatorMapper;
use App\Services\Analysis\SignalDeterminationService;
use App\Services\Analysis\TechnicalIndicatorCalculator;
use App\Services\Analysis\UsFundamentalIndicatorMapper;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use App\Services\Sector\SectorClassificationResolver;
use App\Services\SignalOutcome\SignalOccurrenceMetricsBuilder;
use App\Services\SignalOutcome\SignalOccurrenceRecorder;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
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
        private readonly FinnhubClientInterface $finnhubClient,
        private readonly TechnicalIndicatorCalculator $technicalIndicatorCalculator,
        private readonly FundamentalIndicatorMapper $fundamentalIndicatorMapper,
        private readonly UsFundamentalIndicatorMapper $usFundamentalIndicatorMapper,
        private readonly SignalDeterminationService $signalDeterminationService,
        private readonly BuySignalDeterminationService $buySignalDeterminationService,
        private readonly WeeklyPriceRecorder $weeklyPriceRecorder,
        private readonly SignalOccurrenceRecorder $signalOccurrenceRecorder,
        private readonly SignalOccurrenceMetricsBuilder $signalOccurrenceMetricsBuilder,
        private readonly SectorClassificationResolver $sectorResolver,
    ) {}

    public function execute(ImportBatch $batch): void
    {
        $snapshot = Snapshot::where('import_batch_id', $batch->id)->firstOrFail();

        // Step: market-wide indicators (UC-007). Not gated on the presence
        // of any eligible stock holding.
        $nikkeiHistory = $this->marketIndexClient->fetchWeeklyHistory('nikkei225');
        $sp500History = $this->marketIndexClient->fetchWeeklyHistory('sp500');

        // UC-014 (ADR-0017 D2): keep the fetched index series for excess-return
        // calculation. The recorder never throws, and this runs outside any
        // DB::transaction so a recording failure never rolls back analysis.
        $this->weeklyPriceRecorder->recordIndex('nikkei225', $nikkeiHistory);
        $this->weeklyPriceRecorder->recordIndex('sp500', $sp500History);

        // UC-015 (ADR-0019 D2): a SOX fetch failure must not stop the analysis.
        // The Yahoo client returns [] (no exception) on HTTP errors, so log that too.
        try {
            $soxHistory = $this->marketIndexClient->fetchWeeklyHistory('sox');
            if ($soxHistory === []) {
                Log::warning('FetchExternalMarketDataAction: sox index fetch returned no data', ['index_name' => 'sox']);
            }
            $this->weeklyPriceRecorder->recordIndex('sox', $soxHistory);
        } catch (Throwable $exception) {
            Log::warning('FetchExternalMarketDataAction: sox index fetch failed', [
                'index_name' => 'sox',
                'error' => $exception->getMessage(),
            ]);
        }

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

        $allHoldingSnapshots = HoldingSnapshot::where('snapshot_id', $snapshot->id)
            ->with('holding')
            ->get();

        // CHG-0029 / ADR-0020: mutual funds have no external sector source;
        // they get the fixed 投資信託 category (no API call, cannot fail).
        foreach ($allHoldingSnapshots as $fundHoldingSnapshot) {
            $fundHolding = $fundHoldingSnapshot->holding;

            if ($fundHolding->instrument_type === 'mutual_fund' && $fundHolding->sector_classification_id === null) {
                $fundHolding->forceFill(['sector_classification_id' => $this->sectorResolver->forMutualFund()->id])->save();
            }
        }

        $holdingSnapshots = $allHoldingSnapshots
            ->filter(fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->holding->instrument_type === 'stock');

        foreach ($holdingSnapshots as $holdingSnapshot) {
            $holding = $holdingSnapshot->holding;

            try {
                $priceHistory = $holding->market === 'jp'
                    ? $this->jpStockPriceClient->fetchWeeklyPriceHistory($holding->symbol_code)
                    : $this->usStockPriceClient->fetchWeeklyPriceHistory($holding->symbol_code);

                // UC-014 (ADR-0017 D2): outside any DB::transaction; never throws.
                $this->weeklyPriceRecorder->recordHolding($holding, $priceHistory);

                $sectorClassificationId = $holding->sector_classification_id;

                if ($holding->market === 'jp') {
                    try {
                        $sectorInfo = $this->jQuantsClient->fetchSectorInfo($holding->symbol_code);
                    } catch (HttpClientException $exception) {
                        Log::warning('FetchExternalMarketDataAction: J-Quants sector fetch failed', [
                            'holding_id' => $holding->id,
                            'symbol_code' => $holding->symbol_code,
                            ...($exception instanceof RequestException
                                ? ['http_status' => $exception->response->status()]
                                : ['connection_error' => true]),
                        ]);

                        $sectorInfo = null;
                    }

                    if ($sectorInfo !== null) {
                        $sectorClassificationId = $this->sectorResolver->forJpSector($sectorInfo)->id;
                        $holding->forceFill(['sector_classification_id' => $sectorClassificationId])->save();
                    }
                }

                // CHG-0029 / ADR-0020: US industry from Finnhub. Failure or an
                // unknown industry never blocks the holding (existing value kept).
                if ($holding->market === 'us') {
                    try {
                        $industry = $this->finnhubClient->fetchIndustry($holding->symbol_code);
                    } catch (Throwable) {
                        Log::warning('FetchExternalMarketDataAction: Finnhub industry fetch failed', [
                            'holding_id' => $holding->id,
                            'symbol_code' => $holding->symbol_code,
                        ]);

                        $industry = null;
                    }

                    if ($industry !== null) {
                        $holding->forceFill(['sector_classification_id' => $this->sectorResolver->forUsIndustry($industry)->id])->save();
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
                // $e->getMessage() is safe to log today: MarketData clients'
                // exceptions carry only the request URL/status, never
                // headers (so no J-Quants API key leaks). Re-check this
                // assumption if a client implementation changes.
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

            // UC-014 (ADR-0017 D3): what the transaction determined, recorded
            // to signal_occurrences only after it commits.
            $occurrences = null;

            try {
                DB::transaction(function () use ($holding, $holdingSnapshot, $priceHistory, $row, $nikkeiReturn13w, $sp500Return13w, $sectorAverageReturns, &$occurrences) {
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
                    $avgGrowth = [];

                    // Fundamentals/sector are JP個別株限定 (UC-002業務ルール
                    // "指標計算はJP株・US株の個別株のみ対象" + fundamentals自体はJP限定).
                    if ($holding->market === 'jp') {
                        try {
                            $statements = $this->jQuantsClient->fetchStatements($holding->symbol_code);
                        } catch (HttpClientException $exception) {
                            Log::warning('FetchExternalMarketDataAction: J-Quants statements fetch failed', [
                                'holding_id' => $holding->id,
                                'symbol_code' => $holding->symbol_code,
                                'exception' => $exception->getMessage(),
                                ...($exception instanceof RequestException
                                    ? ['http_status' => $exception->response->status()]
                                    : []),
                            ]);

                            $statements = [];
                        }

                        $currentPrice = $holdingSnapshot->current_price !== null
                            ? (float) $holdingSnapshot->current_price
                            : null;

                        $fundamental = $this->fundamentalIndicatorMapper->map($statements, $currentPrice);

                        // ADR-0015 D2 (Cycle4a): 直近3期平均のYoY成長率
                        // （単年度のrevenue_growth/operating_income_growthとは
                        // 別カラムに保存する）。
                        $avgGrowth = [
                            'avg_revenue_growth' => $this->fundamentalIndicatorMapper->averageAnnualGrowth($statements, 'net_sales'),
                            'avg_operating_income_growth' => $this->fundamentalIndicatorMapper->averageAnnualGrowth($statements, 'operating_profit'),
                        ];

                        FundamentalIndicator::updateOrCreate(
                            ['holding_id' => $holding->id],
                            [
                                ...$fundamental,
                                ...$avgGrowth,
                                'fetched_at' => now(),
                            ],
                        );

                        $pegRatio = $fundamental['peg_ratio'];

                        // UC-006向け財務諸表履歴の保存（docs/architecture/data-model.md
                        // "financial_statements"）: 同じ$statementsを
                        // (holding_id, fiscal_period)単位でUPSERTする。
                        // revenue_yoy_change/operating_income_yoy_changeは
                        // 最新開示行（index 0）にのみ、FundamentalIndicatorMapper::
                        // annualGrowth()（最新FYと前期FYの比較、ADR-0012）で算出した
                        // 現時点の前期通期比を持たせる。
                        foreach ($statements as $index => $statement) {
                            FinancialStatement::updateOrCreate(
                                ['holding_id' => $holding->id, 'fiscal_period' => $statement['disclosed_date']],
                                [
                                    'period_type' => $statement['period_type'],
                                    'fiscal_year_end' => $statement['fiscal_year_end'],
                                    'revenue' => $statement['net_sales'],
                                    'operating_income' => $statement['operating_profit'],
                                    'eps' => $statement['eps'],
                                    'revenue_yoy_change' => $index === 0 ? $this->fundamentalIndicatorMapper->annualGrowth($statements, 'net_sales') : null,
                                    'operating_income_yoy_change' => $index === 0 ? $this->fundamentalIndicatorMapper->annualGrowth($statements, 'operating_profit') : null,
                                    'fetched_at' => now(),
                                ],
                            );
                        }
                    } elseif ($holding->market === 'us') {
                        // US個別株ファンダメンタルズ（ADR-0009）: Finnhubから
                        // 取得したmetrics/reportedFinancialsをUsFundamentalIndicatorMapper
                        // で変換して保存する。financial_statements（UC-006向け）は
                        // ADR-0009のスコープ外のためUS株には保存しない。
                        $metrics = $this->finnhubClient->fetchMetrics($holding->symbol_code) ?? [];
                        $reportedFinancials = $this->finnhubClient->fetchReportedFinancials($holding->symbol_code);

                        $fundamental = $this->usFundamentalIndicatorMapper->map($metrics, $reportedFinancials);

                        FundamentalIndicator::updateOrCreate(
                            ['holding_id' => $holding->id],
                            [...$fundamental, 'fetched_at' => now()],
                        );

                        $pegRatio = $fundamental['peg_ratio'];
                    }

                    $takeProfitTypes = null;

                    // UC-004業務ルール: 含み益+20%未満は利確シグナル判定の対象外.
                    if ((float) $holdingSnapshot->unrealized_gain_rate > self::SIGNAL_GAIN_RATE_THRESHOLD) {
                        $signals = $this->signalDeterminationService->determine(
                            $priceHistory,
                            $marketReturn13w,
                            $sectorReturn13w,
                            $pegRatio,
                            $fundamental['revenue_growth'] ?? null,
                            $fundamental['operating_income_growth'] ?? null,
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

                        $takeProfitTypes = array_column($signals, 'signal_type');
                    }

                    // UC-010業務ルール（ADR-0007）: 買い増しシグナル判定は
                    // 利確シグナル（含み益+20%ゲート）とは判定対象・保存先
                    // ともに独立しており、含み益率による対象銘柄の絞り込みは
                    // 行わない。そのため上記ゲートの外側で全銘柄に対して常に
                    // 実行する。
                    $buySignals = $this->buySignalDeterminationService->determine(
                        $priceHistory,
                        $marketReturn13w,
                        $sectorReturn13w,
                        $pegRatio,
                        per: $fundamental['per'] ?? null,
                        equityRatio: $fundamental['equity_ratio'] ?? null,
                        roe: $fundamental['roe'] ?? null,
                        revenueGrowth: $fundamental['revenue_growth'] ?? null,
                        operatingIncomeGrowth: $fundamental['operating_income_growth'] ?? null,
                        operatingMargin: $fundamental['operating_margin'] ?? null,
                        dividendYield: $fundamental['dividend_yield'] ?? null,
                        // CHG-0025: the mapper's $fundamental never carries the
                        // 3-period averages; they are computed separately into
                        // $avgGrowth (JP only, empty for US), so read them there
                        // or the ADR-0015 D2 rescue never applies to held stocks.
                        avgRevenueGrowth: $avgGrowth['avg_revenue_growth'] ?? null,
                        avgOperatingIncomeGrowth: $avgGrowth['avg_operating_income_growth'] ?? null,
                        // ADR-0026 D3: sector-relative PER condition. Reload the
                        // relation because sector_classification_id may have been
                        // updated above.
                        market: $holding->market,
                        sectorName: $holding->load('sectorClassification')->sectorClassification?->name,
                    );

                    // Re-determination: same drop-then-recreate pattern as the
                    // take-profit signals above, kept in its own table
                    // (buy_signals) so this never touches Signal/`signals`
                    // (ADR-0007 D2).
                    BuySignal::where('holding_snapshot_id', $holdingSnapshot->id)->delete();

                    foreach ($buySignals as $buySignal) {
                        BuySignal::create([
                            'holding_snapshot_id' => $holdingSnapshot->id,
                            'signal_type' => $buySignal['signal_type'],
                            'reason_summary' => $buySignal['reason_summary'],
                        ]);
                    }

                    $occurrences = [
                        'take_profit' => $takeProfitTypes,
                        'buy' => array_column($buySignals, 'signal_type'),
                        'metrics' => $this->signalOccurrenceMetricsBuilder->build(
                            $priceHistory,
                            $technical,
                            [...($fundamental ?? []), ...$avgGrowth],
                        ),
                    ];
                });
            } catch (Throwable $e) {
                // See the safety note on the identical log call above.
                Log::warning('FetchExternalMarketDataAction: holding indicator/signal processing failed', [
                    'holding_id' => $holding->id,
                    'symbol_code' => $holding->symbol_code,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $this->recordSignalOccurrences($holding, $snapshot, $priceHistory, $occurrences);
        }
    }

    /**
     * UC-014 (ADR-0017 D3): append the committed take-profit / buy signals
     * to signal_occurrences. Runs outside the per-holding transaction and
     * the recorder never throws, so a recording failure never affects the
     * saved signals.
     *
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
     * @param  array{take_profit: array<int, string>|null, buy: array<int, string>, metrics: array<string, mixed>}|null  $occurrences
     */
    private function recordSignalOccurrences(Holding $holding, Snapshot $snapshot, array $priceHistory, ?array $occurrences): void
    {
        $observedWeek = $this->signalOccurrenceMetricsBuilder->observedWeek($priceHistory);

        if ($occurrences === null || $observedWeek === null) {
            return;
        }

        if ($occurrences['take_profit'] !== null) {
            $this->signalOccurrenceRecorder->record($holding, 'take_profit', $occurrences['take_profit'], $observedWeek, $snapshot->id, $occurrences['metrics']);
        }

        $this->signalOccurrenceRecorder->record($holding, 'buy', $occurrences['buy'], $observedWeek, $snapshot->id, $occurrences['metrics']);
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
