<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\BuySignal;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\IndicatorObservation;
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
use Illuminate\Support\Carbon;
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
| UC-018: 指標の週次追記保存（indicator_observations） — Red phase
| (CHG-0033 Cycle 1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0027-trade-history-storage.md D4
|   - docs/architecture/data-model.md `indicator_observations`
|   - docs/product/use-cases.md UC-018 基本フロー7（売買前に保存済みの
|     指標を自動で紐付ける。業績指標は当時値を後から復元できないため、
|     記録を先に始める）
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - Every successful per-stock analysis run appends ONE row, whether or
|     not any signal fired (unlike signal_occurrences, which only records
|     fired signals):
|       * FetchExternalMarketDataAction (stock holdings of the import)
|         → source = holding_import
|       * RefreshWatchlistMarketDataAction (unheld watchlist items)
|         → source = watchlist_refresh
|   - observed_at = the time the system obtained the values (now()); it is
|     NOT the weekly bar label date.
|   - metrics keys = the same 17 keys as signal_occurrences.metrics
|     (SignalOccurrenceMetricsBuilder::build()); no health verdict key.
|   - Append only: a second run in the same week appends a second row
|     (rows differ by observed_at); an existing row is never updated or
|     deleted. There is NO unique key on (holding, source, week).
|   - ETF / mutual funds are not recorded (same scope as the signal path).
|     A holding whose weekly price history is empty is not recorded.
|   - Failure isolation: a recording failure never stops the existing
|     signals / buy_signals / watchlist_buy_signals, never throws, and logs
|     a warning with holding_id and source only (metrics are never logged).
|   - Existing signal_occurrences behaviour is unchanged.
|
| Failure isolation is simulated with a DB::listen() listener that throws
| for queries touching indicator_observations (surfaces inside the real
| recorder's DB call; no DDL, no test double).
|
| Expected Red: App\Models\IndicatorObservation and the indicator_observations
| table do not exist yet, and neither Action writes observations.
|
*/

/**
 * @return list<string>
 */
function ioMetricKeys(): array
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
function ioWeekly(array $closes, int $volume = 100000): array
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
 * Calm 52-week rise: no take-profit / buy signal fires without fundamentals.
 *
 * @return array<int, float>
 */
function ioCalmCloses(): array
{
    return range(100, 151);
}

/**
 * 19 flat weeks then a spike → bollinger_overheat (take-profit signal).
 *
 * @return array<int, float>
 */
function ioOverheatCloses(): array
{
    return array_merge(array_fill(0, 19, 100.0), [130.0]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function ioHealthyStatements(): array
{
    $latest = [
        'net_sales' => 120000.0, 'operating_profit' => 15000.0, 'profit' => 10000.0, 'eps' => 120.0,
        'book_value_per_share' => 800.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
        'dividend_per_share_annual' => 30.0, 'payout_ratio_annual' => 0.30,
    ];
    $quarters = ['FY', '3Q', '2Q', '1Q'];
    $statements = [];

    for ($i = 0; $i < 4; $i++) {
        $statements[] = array_merge($latest, [
            'disclosed_date' => "2026Q{$i}", 'period_type' => $quarters[$i], 'fiscal_year_end' => '2026-03-31',
        ]);
    }

    $statements[4] = array_merge($latest, [
        'disclosed_date' => '2025FY', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31',
        'net_sales' => 100000.0, 'operating_profit' => 12000.0, 'eps' => 100.0,
    ]);

    return $statements;
}

/**
 * @return array<string, array<int, array{date: string, close: float, volume: int}>>
 */
function ioIndexHistories(): array
{
    return [
        'nikkei225' => ioWeekly(array_map(fn (int $i) => 30000.0 - 100.0 * $i, range(0, 13)), 0),
        'sp500' => ioWeekly(array_map(fn (int $i) => 4500.0 + 20.0 * $i, range(0, 13)), 0),
    ];
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ioImportBatch(): array
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

    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);

    return [$batch, $snapshot];
}

function ioHolding(string $code, string $instrumentType = 'stock'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => $instrumentType,
        'symbol_name' => '銘柄'.$code,
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ]);
}

function ioHoldingSnapshot(Snapshot $snapshot, Holding $holding, float $unrealizedGainRate): HoldingSnapshot
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
function ioBindFakes(array $jp, array $statements = [], ?array $indices = null): void
{
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient($jp));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient($indices ?? ioIndexHistories()));
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(statementsResponses: $statements));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);
}

/**
 * Arms a listener that makes every query touching indicator_observations
 * throw. Returns a disarm callback.
 */
function ioFailObservationQueries(): \Closure
{
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'indicator_observations')) {
            throw new RuntimeException('simulated indicator_observations DB failure');
        }
    });

    return function () use (&$armed) {
        $armed = false;
    };
}

describe('UC-018 指標の週次追記保存（CSV取込の分析処理: FetchExternalMarketDataAction）', function () {
    test('シグナルが出なかった株式銘柄でも、取得した指標が取得日時つきで追記される', function () {
        // Arrange
        Carbon::setTestNow('2026-09-24 10:00:00');
        [$batch, $snapshot] = ioImportBatch();
        $calm = ioHolding('9103');
        $calmHs = ioHoldingSnapshot($snapshot, $calm, 25.0);
        ioBindFakes(jp: ['9103' => ioWeekly(ioCalmCloses())]); // 財務データなし → シグナルは出ない

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert (fixture precondition: no signal fired, so no occurrence either)
        expect(Signal::where('holding_snapshot_id', $calmHs->id)->count())->toBe(0);
        expect(BuySignal::where('holding_snapshot_id', $calmHs->id)->count())->toBe(0);
        expect(SignalOccurrence::where('holding_id', $calm->id)->count())->toBe(0);

        // Assert: one observation is appended anyway
        $observation = IndicatorObservation::where('holding_id', $calm->id)->sole();
        expect($observation->source)->toBe('holding_import');
        expect($observation->observed_at->toDateTimeString())->toBe('2026-09-24 10:00:00');
        expect(array_keys($observation->metrics))->toEqualCanonicalizing(ioMetricKeys());
        expect((float) $observation->metrics['close'])->toBe(151.0);
        expect($observation->metrics['rsi'])->not->toBeNull();
        expect($observation->metrics['per'])->toBeNull(); // 財務データなし → PER不明（記録なしではなく「不明」として残る）
    });

    test('シグナルが出た銘柄でも指標は追記され、シグナル発生記録は従来どおり作られる', function () {
        // Arrange
        [$batch, $snapshot] = ioImportBatch();
        $holding = ioHolding('9101');
        ioHoldingSnapshot($snapshot, $holding, 25.0); // >20% → 利確判定対象
        ioBindFakes(jp: ['9101' => ioWeekly(ioOverheatCloses())]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(SignalOccurrence::where('holding_id', $holding->id)->where('source', 'take_profit')->count())->toBeGreaterThan(0);
        expect(IndicatorObservation::where('holding_id', $holding->id)->where('source', 'holding_import')->count())->toBe(1);
    });

    test('ETF・投資信託は指標を記録しない', function () {
        // Arrange
        [$batch, $snapshot] = ioImportBatch();
        $etf = ioHolding('1306', 'etf');
        ioHoldingSnapshot($snapshot, $etf, 5.0);
        $stock = ioHolding('9103');
        ioHoldingSnapshot($snapshot, $stock, 5.0);
        ioBindFakes(jp: ['1306' => ioWeekly(ioCalmCloses()), '9103' => ioWeekly(ioCalmCloses())]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(IndicatorObservation::where('holding_id', $stock->id)->count())->toBe(1);
        expect(IndicatorObservation::where('holding_id', $etf->id)->count())->toBe(0);
    });

    test('株価履歴を取得できなかった銘柄は記録せず、他の銘柄の記録は続く', function () {
        // Arrange
        [$batch, $snapshot] = ioImportBatch();
        $noHistory = ioHolding('9104');
        ioHoldingSnapshot($snapshot, $noHistory, 5.0);
        $ok = ioHolding('9103');
        ioHoldingSnapshot($snapshot, $ok, 5.0);
        ioBindFakes(jp: ['9103' => ioWeekly(ioCalmCloses())]); // 9104 → 空の履歴

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(IndicatorObservation::where('holding_id', $noHistory->id)->count())->toBe(0);
        expect(IndicatorObservation::where('holding_id', $ok->id)->count())->toBe(1);
    });

    test('同じ週に分析処理を2回実行すると行が追記され、1回目の行は変更されない（追記のみ）', function () {
        // Arrange
        [$batch, $snapshot] = ioImportBatch();
        $holding = ioHolding('9103');
        ioHoldingSnapshot($snapshot, $holding, 5.0);
        $jp = ['9103' => ioWeekly(ioCalmCloses())];

        Carbon::setTestNow('2026-09-24 10:00:00');
        ioBindFakes($jp);
        app(FetchExternalMarketDataAction::class)->execute($batch);
        $first = IndicatorObservation::where('holding_id', $holding->id)->sole();
        $firstSnapshot = $first->only(['id', 'source', 'observed_at', 'metrics']);

        // Act
        Carbon::setTestNow('2026-09-25 18:30:00');
        ioBindFakes($jp);
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        $rows = IndicatorObservation::where('holding_id', $holding->id)->orderBy('id')->get();
        expect($rows)->toHaveCount(2);
        expect($rows[0]->only(['id', 'source', 'observed_at', 'metrics']))->toEqual($firstSnapshot);
        expect($rows[1]->observed_at->toDateTimeString())->toBe('2026-09-25 18:30:00');
    });

    test('指標の保存がDBエラーで失敗しても、利確シグナルは従来どおり保存され例外は投げられず、ログには銘柄IDと記録元だけの警告が残る', function () {
        // Arrange
        Log::spy();
        [$batch, $snapshot] = ioImportBatch();
        $holding = ioHolding('9101');
        $holdingSnapshot = ioHoldingSnapshot($snapshot, $holding, 25.0);
        ioBindFakes(jp: ['9101' => ioWeekly(ioOverheatCloses())]);
        $disarm = ioFailObservationQueries();

        // Act (reaching the assertions means no exception escaped)
        app(FetchExternalMarketDataAction::class)->execute($batch);
        $disarm();

        // Assert: existing outputs are intact
        expect(Signal::where('holding_snapshot_id', $holdingSnapshot->id)->pluck('signal_type')->all())->toContain('bollinger_overheat');
        expect(SignalOccurrence::where('holding_id', $holding->id)->where('source', 'take_profit')->count())->toBeGreaterThan(0);

        // Assert: failure logged with holding_id + source, and no metrics in the context
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['holding_id'] ?? null) === $holding->id
                && ($context['source'] ?? null) === 'holding_import'
                && ! array_key_exists('metrics', $context))
            ->atLeast()->once();
    });
});

describe('UC-018 指標の週次追記保存（ウォッチリスト一括更新: RefreshWatchlistMarketDataAction）', function () {
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
                'net_sales' => 100000.0, 'operating_profit' => 10000.0, 'eps' => 100.0, 'profit' => 7800.0,
                'book_value_per_share' => 780.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
                'dividend_per_share_annual' => 32.0, 'payout_ratio_annual' => 0.30,
            ],
        ];

        ioBindFakes(
            jp: [$code => ioWeekly(array_map(fn (int $i) => 755.0 + 5 * $i, range(0, 59)))],
            statements: [$code => $statements],
            indices: [
                'nikkei225' => ioWeekly(array_map(fn (int $i) => 30000.0 + 5 * $i, range(0, 59)), 0),
                'sp500' => ioWeekly(array_map(fn (int $i) => 5000.0 + 5 * $i, range(0, 59)), 0),
            ],
        );
    };

    $watchlistHolding = function (string $code): Holding {
        $holding = ioHolding($code);
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

    test('未保有ウォッチリスト銘柄の指標が、watchlist_refreshとして取得日時・判定根拠値つきで追記される', function () use ($bindWatchlist, $watchlistHolding) {
        // Arrange
        Carbon::setTestNow('2026-09-24 10:00:00');
        $holding = $watchlistHolding('7777');
        $bindWatchlist('7777');

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert (fixture precondition: existing behaviour unchanged)
        expect(WatchlistBuySignal::where('holding_id', $holding->id)->pluck('signal_type')->all())->toContain('peg_undervalued');

        // Assert
        $observation = IndicatorObservation::where('holding_id', $holding->id)->sole();
        expect($observation->source)->toBe('watchlist_refresh');
        expect($observation->observed_at->toDateTimeString())->toBe('2026-09-24 10:00:00');
        expect(array_keys($observation->metrics))->toEqualCanonicalizing(ioMetricKeys());
        expect((float) $observation->metrics['close'])->toBe(1050.0);
        expect((float) $observation->metrics['per'])->toEqualWithDelta(10.0, 0.0001);
        expect(IndicatorObservation::where('source', 'holding_import')->count())->toBe(0);
    });

    test('同じ週に一括更新を2回実行すると行が追記され、1回目の行は変更されない（追記のみ）', function () use ($bindWatchlist, $watchlistHolding) {
        // Arrange
        $holding = $watchlistHolding('7777');
        Carbon::setTestNow('2026-09-24 10:00:00');
        $bindWatchlist('7777');
        app(RefreshWatchlistMarketDataAction::class)->execute();
        $first = IndicatorObservation::where('holding_id', $holding->id)->sole();
        $firstSnapshot = $first->only(['id', 'source', 'observed_at', 'metrics']);

        // Act
        Carbon::setTestNow('2026-09-25 18:30:00');
        $bindWatchlist('7777');
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert
        $rows = IndicatorObservation::where('holding_id', $holding->id)->orderBy('id')->get();
        expect($rows)->toHaveCount(2);
        expect($rows[0]->only(['id', 'source', 'observed_at', 'metrics']))->toEqual($firstSnapshot);
    });

    test('指標の保存がDBエラーで失敗しても、押し目買いシグナルは従来どおり保存され例外は投げられない', function () use ($bindWatchlist, $watchlistHolding) {
        // Arrange
        Log::spy();
        $holding = $watchlistHolding('7777');
        $bindWatchlist('7777');
        $disarm = ioFailObservationQueries();

        // Act (reaching the assertions means no exception escaped)
        app(RefreshWatchlistMarketDataAction::class)->execute();
        $disarm();

        // Assert
        expect(WatchlistBuySignal::where('holding_id', $holding->id)->pluck('signal_type')->all())->toContain('peg_undervalued');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['holding_id'] ?? null) === $holding->id
                && ($context['source'] ?? null) === 'watchlist_refresh')
            ->atLeast()->once();
    });
});
