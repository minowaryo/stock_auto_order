<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\BuySignal;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\SignalOccurrence;
use App\Models\Snapshot;
use App\Models\WatchlistBuySignal;
use App\Models\WatchlistItem;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-014: シグナル発生記録の追記 — Red phase (CHG-0020 Cycle2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D3
|   - docs/architecture/data-model.md `signal_occurrences`
|   - docs/product/use-cases.md UC-014 基本フロー（記録）3〜4, エラーケース
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - FetchExternalMarketDataAction (per stock holding) records, after the
|     existing signals / buy_signals are saved:
|       * source take_profit for the Signal rows (only when take-profit
|         determination actually ran — the existing +20% gate)
|       * source buy for the BuySignal rows
|     snapshot_id = the batch's snapshot id.
|   - RefreshWatchlistMarketDataAction records source watchlist_buy for the
|     watchlist_buy_signals rows, snapshot_id = null.
|   - observed_week = WeekDateNormalizer::weekStart() of the LAST row of the
|     price history used for determination (fixtures end on Sun 2026-09-20
|     → 2026-09-21).
|   - metrics keys = uc014oMetricKeys(); values from the technical indicator
|     result / last close / the fundamental values the action already has
|     (null when unavailable). The fundamental health status is NOT required.
|   - Existing signals / buy_signals / watchlist_buy_signals behaviour is
|     unchanged; running twice in the same week does not duplicate.
|
| Failure isolation: simulated with a DB::listen() listener that throws for
| queries touching signal_occurrences (surfaces inside the real
| SignalOccurrenceRecorder's DB call; no DDL, no test double). This works
| whether or not the Action wraps the recorder call itself.
|
| Expected Red: no signal_occurrences rows are written (the wiring and
| App\Services\SignalOutcome\SignalOccurrenceRecorder do not exist yet).
| The "no signals → no occurrences" test is a regression guard and already
| passes in Red.
|
*/

/**
 * @return list<string>
 */
function uc014oMetricKeys(): array
{
    return [
        'close', 'rsi', 'week52_high', 'relative_strength_vs_market', 'relative_strength_vs_sector', 'ma75_trend_rising',
        'per', 'pbr', 'peg_ratio', 'roe', 'equity_ratio', 'operating_margin',
        'revenue_growth', 'operating_income_growth', 'avg_revenue_growth', 'avg_operating_income_growth', 'dividend_yield',
    ];
}

/**
 * Sunday-stamped (JP-style) weekly bars ending on Sun 2026-09-20
 * (= week of Mon 2026-09-21).
 *
 * @param  array<int, float|int>  $closes  ascending weekly closes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function uc014oWeekly(array $closes, int $volume = 100000): array
{
    $last = new \DateTimeImmutable('2026-09-20');
    $count = count($closes);
    $rows = [];

    foreach (array_values($closes) as $i => $close) {
        $weeksBack = $count - 1 - $i;
        $rows[] = [
            'date' => $last->modify("-{$weeksBack} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => $volume,
        ];
    }

    return $rows;
}

/**
 * 19 flat weeks then a spike → bollinger_overheat (same shape as
 * UC014WeeklyPriceRecordingTest's uc014OverheatCloses()).
 *
 * @return array<int, float>
 */
function uc014oOverheatCloses(): array
{
    return array_merge(array_fill(0, 19, 100.0), [130.0]);
}

/**
 * Verified rsi_oversold_rebound fixture (FetchExternalMarketDataActionBuySignalTest
 * fmbRsiReboundPriceHistory): week52_high=138, last close 95.
 *
 * @return array<int, float>
 */
function uc014oRsiReboundCloses(): array
{
    return array_merge(range(100, 138), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
}

/**
 * Calm 52-week rise: no take-profit / buy signal fires without fundamentals
 * (fmbCalmPriceHistory).
 *
 * @return array<int, float>
 */
function uc014oCalmCloses(): array
{
    return range(100, 151);
}

/**
 * Healthy / undervalued JP statements (fmbHealthyUndervaluedStatements):
 * with current_price 950 → PER = 950/120, PBR = 950/800.
 *
 * @return array<int, array<string, mixed>>
 */
function uc014oHealthyStatements(): array
{
    $latest = [
        'net_sales' => 120000.0,
        'operating_profit' => 15000.0,
        'profit' => 10000.0,
        'eps' => 120.0,
        'book_value_per_share' => 800.0,
        'equity_to_asset_ratio' => 0.55,
        'roe' => 0.125,
        'dividend_per_share_annual' => 30.0,
        'payout_ratio_annual' => 0.30,
    ];

    $quarters = ['FY', '3Q', '2Q', '1Q'];
    $statements = [];

    for ($i = 0; $i < 4; $i++) {
        $statements[] = array_merge($latest, [
            'disclosed_date' => "2026Q{$i}",
            'period_type' => $quarters[$i],
            'fiscal_year_end' => '2026-03-31',
        ]);
    }

    $statements[4] = array_merge($latest, [
        'disclosed_date' => '2025FY',
        'period_type' => 'FY',
        'fiscal_year_end' => '2025-03-31',
        'net_sales' => 100000.0,
        'operating_profit' => 12000.0,
        'eps' => 100.0,
    ]);

    return $statements;
}

/**
 * nikkei225 13-week return exactly -35.0% (fmbNikkeiHistory), so the
 * rsi_oversold_rebound fixture satisfies buy precondition B.
 *
 * @return array<string, array<int, array{date: string, close: float, volume: int}>>
 */
function uc014oFetchIndexHistories(): array
{
    return [
        'nikkei225' => uc014oWeekly(array_map(fn (int $i) => 30000.0 - 807.6923076923077 * $i, range(0, 13)), 0),
        'sp500' => uc014oWeekly(array_map(fn (int $i) => 4500.0 + 20.0 * $i, range(0, 13)), 0),
    ];
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function uc014oImportBatch(): array
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => now(),
    ]);

    return [$batch, $snapshot];
}

function uc014oHolding(string $code, string $name = '銘柄'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => $name,
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ]);
}

function uc014oHoldingSnapshot(Snapshot $snapshot, Holding $holding, float $unrealizedGainRate): HoldingSnapshot
{
    return HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 800,
        'current_price' => 950,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 15000,
        'unrealized_gain_rate' => $unrealizedGainRate,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
}

/**
 * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $jp
 * @param  array<string, array<int, array<string, mixed>>>  $statements
 * @param  array<string, array<int, array{date: string, close: float, volume: int}>>|null  $indices
 */
function uc014oBindFakes(array $jp, array $statements = [], ?array $indices = null): void
{
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient($jp));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient($indices ?? uc014oFetchIndexHistories()));
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(statementsResponses: $statements));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);
}

/**
 * @return list<string>
 */
function uc014oOccurrenceTypes(Holding $holding, string $source): array
{
    return SignalOccurrence::where('holding_id', $holding->id)
        ->where('source', $source)
        ->pluck('signal_type')->sort()->values()->all();
}

/**
 * Arms a listener that makes every query touching signal_occurrences throw
 * (a DB-layer failure inside the real recorder). Returns a disarm callback.
 */
function uc014oFailOccurrenceQueries(): \Closure
{
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'signal_occurrences')) {
            throw new RuntimeException('simulated signal_occurrences DB failure');
        }
    });

    return function () use (&$armed) {
        $armed = false;
    };
}

describe('UC-014 シグナル発生記録の追記（CSV取込の分析処理: FetchExternalMarketDataAction）', function () {
    test('利確シグナルはtake_profit、買い増しシグナルはbuyとして、発生週・スナップショット・判定根拠値つきでシグナル発生記録に追記される', function () {
        // Arrange
        [$batch, $snapshot] = uc014oImportBatch();
        $takeProfit = uc014oHolding('9101', '利確対象銘柄');
        $takeProfitHs = uc014oHoldingSnapshot($snapshot, $takeProfit, 25.0); // >20% → 利確判定対象
        $buy = uc014oHolding('9102', '買い増し対象銘柄');
        $buyHs = uc014oHoldingSnapshot($snapshot, $buy, 10.0); // 利確判定対象外

        uc014oBindFakes(
            jp: ['9101' => uc014oWeekly(uc014oOverheatCloses()), '9102' => uc014oWeekly(uc014oRsiReboundCloses())],
            statements: ['9102' => uc014oHealthyStatements()],
        );

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert (fixture preconditions: existing signal behaviour unchanged)
        $takeProfitSignals = Signal::where('holding_snapshot_id', $takeProfitHs->id)->pluck('signal_type')->sort()->values()->all();
        $takeProfitBuySignals = BuySignal::where('holding_snapshot_id', $takeProfitHs->id)->pluck('signal_type')->sort()->values()->all();
        $buyBuySignals = BuySignal::where('holding_snapshot_id', $buyHs->id)->pluck('signal_type')->sort()->values()->all();
        expect($takeProfitSignals)->toContain('bollinger_overheat');
        expect($buyBuySignals)->toContain('rsi_oversold_rebound');
        expect(Signal::where('holding_snapshot_id', $buyHs->id)->count())->toBe(0);

        // Assert: occurrences mirror exactly the saved rows per source
        expect(uc014oOccurrenceTypes($takeProfit, 'take_profit'))->toBe($takeProfitSignals);
        expect(uc014oOccurrenceTypes($takeProfit, 'buy'))->toBe($takeProfitBuySignals);
        expect(uc014oOccurrenceTypes($buy, 'buy'))->toBe($buyBuySignals);
        expect(uc014oOccurrenceTypes($buy, 'take_profit'))->toBe([]); // +20%ゲート外は利確の記録なし
        expect(SignalOccurrence::where('source', 'watchlist_buy')->count())->toBe(0);

        // Assert: take_profit occurrence details
        $tp = SignalOccurrence::where('holding_id', $takeProfit->id)->where('source', 'take_profit')->where('signal_type', 'bollinger_overheat')->sole();
        expect($tp->observed_week->toDateString())->toBe('2026-09-21');
        expect($tp->snapshot_id)->toBe($snapshot->id);
        expect(array_keys($tp->metrics))->toEqualCanonicalizing(uc014oMetricKeys());
        expect((float) $tp->metrics['close'])->toBe(130.0);
        expect($tp->metrics['rsi'])->not->toBeNull();
        expect($tp->metrics['per'])->toBeNull(); // statementsなし → PER不明
        expect($tp->metrics['relative_strength_vs_sector'])->toBeNull(); // セクター不明

        // Assert: buy occurrence details (fundamentals from the fake statements)
        $bo = SignalOccurrence::where('holding_id', $buy->id)->where('source', 'buy')->where('signal_type', 'rsi_oversold_rebound')->sole();
        expect($bo->observed_week->toDateString())->toBe('2026-09-21');
        expect($bo->snapshot_id)->toBe($snapshot->id);
        expect(array_keys($bo->metrics))->toEqualCanonicalizing(uc014oMetricKeys());
        expect((float) $bo->metrics['close'])->toBe(95.0);
        expect((float) $bo->metrics['week52_high'])->toBe(138.0);
        expect($bo->metrics['rsi'])->not->toBeNull();
        expect($bo->metrics['relative_strength_vs_market'])->not->toBeNull();
        expect((float) $bo->metrics['per'])->toEqualWithDelta(950 / 120, 0.0001);
        expect((float) $bo->metrics['pbr'])->toEqualWithDelta(950 / 800, 0.0001);
    });

    test('同じ週に分析処理を2回実行してもシグナル発生記録は重複しない', function () {
        // Arrange
        [$batch, $snapshot] = uc014oImportBatch();
        $takeProfit = uc014oHolding('9101');
        uc014oHoldingSnapshot($snapshot, $takeProfit, 25.0);
        $buy = uc014oHolding('9102');
        uc014oHoldingSnapshot($snapshot, $buy, 10.0);
        $jp = ['9101' => uc014oWeekly(uc014oOverheatCloses()), '9102' => uc014oWeekly(uc014oRsiReboundCloses())];

        uc014oBindFakes($jp);
        app(FetchExternalMarketDataAction::class)->execute($batch);
        $countAfterFirst = SignalOccurrence::count();

        // Act
        uc014oBindFakes($jp);
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect($countAfterFirst)->toBeGreaterThan(0);
        expect(SignalOccurrence::count())->toBe($countAfterFirst);
    });

    test('シグナルが出なかった銘柄はシグナル発生記録を作らない', function () {
        // Arrange
        [$batch, $snapshot] = uc014oImportBatch();
        $calm = uc014oHolding('9103', 'シグナルなし銘柄');
        $calmHs = uc014oHoldingSnapshot($snapshot, $calm, 25.0);
        uc014oBindFakes(jp: ['9103' => uc014oWeekly(uc014oCalmCloses())]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert (fixture precondition + expectation)
        expect(Signal::where('holding_snapshot_id', $calmHs->id)->count())->toBe(0);
        expect(BuySignal::where('holding_snapshot_id', $calmHs->id)->count())->toBe(0);
        expect(SignalOccurrence::where('holding_id', $calm->id)->count())->toBe(0);
    });

    test('シグナル発生記録の保存がDBエラーで失敗しても、利確シグナル・買い増しシグナルは従来どおり保存され例外は投げられず、ログに警告が残る（UC-014エラーケース）', function () {
        // Arrange
        Log::spy();
        [$batch, $snapshot] = uc014oImportBatch();
        $takeProfit = uc014oHolding('9101');
        $takeProfitHs = uc014oHoldingSnapshot($snapshot, $takeProfit, 25.0);
        $buy = uc014oHolding('9102');
        $buyHs = uc014oHoldingSnapshot($snapshot, $buy, 10.0);
        uc014oBindFakes(jp: ['9101' => uc014oWeekly(uc014oOverheatCloses()), '9102' => uc014oWeekly(uc014oRsiReboundCloses())]);
        $disarm = uc014oFailOccurrenceQueries();

        // Act (reaching the assertions means no exception escaped)
        app(FetchExternalMarketDataAction::class)->execute($batch);
        $disarm();

        // Assert: existing outputs are intact
        expect(Signal::where('holding_snapshot_id', $takeProfitHs->id)->pluck('signal_type')->all())->toContain('bollinger_overheat');
        expect(BuySignal::where('holding_snapshot_id', $buyHs->id)->pluck('signal_type')->all())->toContain('rsi_oversold_rebound');

        // Assert: the recording failure was attempted and logged with holding_id + source
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['holding_id'] ?? null) === $takeProfit->id
                && ($context['source'] ?? null) === 'take_profit')
            ->atLeast()->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['holding_id'] ?? null) === $buy->id
                && ($context['source'] ?? null) === 'buy')
            ->atLeast()->once();
    });
});

describe('UC-014 シグナル発生記録の追記（ウォッチリスト一括更新: RefreshWatchlistMarketDataAction）', function () {
    /**
     * UC012WatchlistRefreshTest の peg_undervalued 低成長フィクスチャ
     * （60週かけて755→1050、PER=1050/105=10.0、配当利回り≈3.33%）。
     */
    $bindWatchlist = function (string $code): void {
        $statements = [
            [
                'disclosed_date' => '2026-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31',
                'net_sales' => 103000.0, 'operating_profit' => 10300.0, 'profit' => 8000.0, 'eps' => 105.0,
                'book_value_per_share' => 800.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
                'dividend_per_share_annual' => 35.0, 'payout_ratio_annual' => 0.30,
            ],
            [
                'disclosed_date' => '2025-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31',
                'net_sales' => 100000.0, 'operating_profit' => 10000.0, 'profit' => 7800.0, 'eps' => 100.0,
                'book_value_per_share' => 780.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
                'dividend_per_share_annual' => 32.0, 'payout_ratio_annual' => 0.30,
            ],
        ];

        uc014oBindFakes(
            jp: [$code => uc014oWeekly(array_map(fn (int $i) => 755.0 + 5 * $i, range(0, 59)))],
            statements: [$code => $statements],
            indices: [
                'nikkei225' => uc014oWeekly(array_map(fn (int $i) => 30000.0 + 5 * $i, range(0, 59)), 0),
                'sp500' => uc014oWeekly(array_map(fn (int $i) => 5000.0 + 5 * $i, range(0, 59)), 0),
            ],
        );
    };

    $watchlistHolding = function (string $code): Holding {
        $holding = uc014oHolding($code, 'ウォッチ銘柄');
        WatchlistItem::create([
            'holding_id' => $holding->id,
            'folder_name' => 'テーマ',
            'exchange_label' => '東Ｐ',
            'source' => 'rakuten_favorites_csv',
            'last_seen_in_csv_at' => now(),
            'registered_at' => now(),
        ]);

        return $holding;
    };

    test('未保有ウォッチリスト銘柄の押し目買いシグナルはwatchlist_buyとして、スナップショットなし・発生週・判定根拠値つきで記録される', function () use ($bindWatchlist, $watchlistHolding) {
        // Arrange
        $holding = $watchlistHolding('7777');
        $bindWatchlist('7777');

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert (fixture precondition: existing behaviour unchanged)
        $saved = WatchlistBuySignal::where('holding_id', $holding->id)->pluck('signal_type')->sort()->values()->all();
        expect($saved)->toContain('peg_undervalued');
        expect(Signal::count())->toBe(0);
        expect(BuySignal::count())->toBe(0);

        // Assert
        expect(uc014oOccurrenceTypes($holding, 'watchlist_buy'))->toBe($saved);
        expect(SignalOccurrence::whereIn('source', ['take_profit', 'buy'])->count())->toBe(0);

        $occurrence = SignalOccurrence::where('holding_id', $holding->id)->where('signal_type', 'peg_undervalued')->sole();
        expect($occurrence->source)->toBe('watchlist_buy');
        expect($occurrence->snapshot_id)->toBeNull();
        expect($occurrence->observed_week->toDateString())->toBe('2026-09-21');
        expect(array_keys($occurrence->metrics))->toEqualCanonicalizing(uc014oMetricKeys());
        expect((float) $occurrence->metrics['close'])->toBe(1050.0);
        expect($occurrence->metrics['rsi'])->not->toBeNull();
        expect((float) $occurrence->metrics['per'])->toEqualWithDelta(10.0, 0.0001);
        expect($occurrence->metrics['relative_strength_vs_sector'])->toBeNull();
    });

    test('同じ週に一括更新を2回実行してもwatchlist_buyのシグナル発生記録は重複しない', function () use ($bindWatchlist, $watchlistHolding) {
        // Arrange
        $holding = $watchlistHolding('7777');
        $bindWatchlist('7777');
        app(RefreshWatchlistMarketDataAction::class)->execute();
        $countAfterFirst = SignalOccurrence::where('holding_id', $holding->id)->count();

        // Act
        $bindWatchlist('7777');
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert
        expect($countAfterFirst)->toBeGreaterThan(0);
        expect(SignalOccurrence::where('holding_id', $holding->id)->count())->toBe($countAfterFirst);
    });
});
