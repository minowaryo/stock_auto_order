<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\BuySignal;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\IndexWeeklyPrice;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\WatchlistItem;
use App\Models\WeeklyPrice;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Facades\Log;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-014: シグナル結果の前向き記録 — 週次価格履歴の保存（Red phase, CHG-0020 Cycle1）
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D2
|   - docs/architecture/data-model.md `weekly_prices` / `index_weekly_prices`
|   - docs/product/use-cases.md UC-014 基本フロー（記録）1〜3, エラーケース
|       「週次価格履歴の保存に失敗（一部銘柄）→ 既存のシグナル判定・保存は継続」
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - FetchExternalMarketDataAction::execute() records the nikkei225/sp500
|     histories it already fetches into index_weekly_prices, and every eligible
|     stock holding's fetched price history into weekly_prices (via
|     WeeklyPriceRecorder, dates normalized to the Monday week start, first
|     row per week wins, UPSERT).
|   - RefreshWatchlistMarketDataAction does the same for the unheld watchlist
|     holdings it refreshes and for the indices it fetches.
|   - Existing signals / buy_signals / watchlist_buy_signals behaviour is
|     unchanged (no new external API call either — the Fakes below are the
|     same ones the existing pipeline already consumes).
|
| Not yet implemented: WeeklyPrice / IndexWeeklyPrice models, the
| weekly_prices / index_weekly_prices tables and the wiring in both Actions.
| Expected Red: "Class App\Models\WeeklyPrice not found" / missing table.
|
*/

/**
 * Weekly bars ending on $lastDate, stepping back 7 days per bar
 * (so JP-style Sunday dates stay Sundays, US-style Mondays stay Mondays).
 *
 * @param  array<int, float>  $closes  ascending weekly closes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function uc014Weekly(string $lastDate, array $closes, int $volume = 100000): array
{
    $last = new \DateTimeImmutable($lastDate);
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
 * @return array<int, float>
 */
function uc014Rising(float $start, int $weeks, float $step = 5.0): array
{
    return array_map(fn (int $i) => $start + $i * $step, range(0, $weeks - 1));
}

/**
 * 19 flat weeks then a spike — produces bollinger_overheat
 * (same fixture shape as FetchExternalMarketDataActionTest's
 * 「利確シグナル判定」block).
 *
 * @return array<int, float>
 */
function uc014OverheatCloses(): array
{
    return array_merge(array_fill(0, 19, 100.0), [130.0]);
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function uc014ImportBatch(): array
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

function uc014Holding(string $code, string $market, string $name = '銘柄'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => $market,
        'instrument_type' => 'stock',
        'symbol_name' => $name,
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ]);
}

function uc014HoldingSnapshot(Snapshot $snapshot, Holding $holding, float $unrealizedGainRate): HoldingSnapshot
{
    return HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 100,
        'current_price' => 130,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 300,
        'unrealized_gain_rate' => $unrealizedGainRate,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
}

/**
 * nikkei225: JP-style Sunday dates (30 weeks ending Sun 2026-09-20).
 * sp500:     US-style Monday dates (30 weeks ending Mon 2026-09-21).
 *
 * @return array<string, array<int, array{date: string, close: float, volume: int}>>
 */
function uc014IndexHistories(): array
{
    return [
        'nikkei225' => uc014Weekly('2026-09-20', uc014Rising(40000.0, 30, 100.0), 0),
        'sp500' => uc014Weekly('2026-09-21', uc014Rising(6000.0, 30, 20.0), 0),
    ];
}

/**
 * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $jp
 * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $us
 * @param  array<int, string>  $jpThrowsFor
 */
function uc014BindFakes(array $jp = [], array $us = [], array $jpThrowsFor = []): void
{
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient($jp, $jpThrowsFor));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient($us));
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient(uc014IndexHistories()));
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);
}

describe('UC-014 週次価格履歴の保存（CSV取込の分析処理: FetchExternalMarketDataAction）', function () {
    test('JP株・US株の保有銘柄の週足が週ごとに保存され、日経平均・S&P500の週足も保存される。既存の利確シグナルは従来どおり作成される', function () {
        // Arrange
        [$batch, $snapshot] = uc014ImportBatch();
        $jp = uc014Holding('7203', 'jp', 'トヨタ自動車');
        $us = uc014Holding('AAPL', 'us', 'アップル');
        $jpHoldingSnapshot = uc014HoldingSnapshot($snapshot, $jp, 25.0); // >20% -> take-profit判定対象
        uc014HoldingSnapshot($snapshot, $us, 10.0);

        // JP: 20 Sunday-stamped weekly bars (ending Sun 2026-09-20 = week of Mon 2026-09-21)
        $jpHistory = uc014Weekly('2026-09-20', uc014OverheatCloses(), 1000);

        // US: 30 Monday-stamped bars + Yahoo's trailing last-trading-day bar (Fri 2026-09-25)
        $usHistory = uc014Weekly('2026-09-21', uc014Rising(200.0, 30, 1.0), 5000);
        $usHistory[] = ['date' => '2026-09-25', 'close' => 229.0, 'volume' => 1234];

        uc014BindFakes(jp: ['7203' => $jpHistory], us: ['AAPL' => $usHistory]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert: weekly_prices — one row per distinct week
        expect(WeeklyPrice::where('holding_id', $jp->id)->count())->toBe(20);
        expect(WeeklyPrice::where('holding_id', $us->id)->count())->toBe(30);
        $this->assertDatabaseHas('weekly_prices', ['holding_id' => $jp->id, 'week_date' => '2026-09-21', 'close' => 130.0, 'volume' => 1000]);
        $this->assertDatabaseHas('weekly_prices', ['holding_id' => $jp->id, 'week_date' => '2026-05-11', 'close' => 100.0]);
        // trailing Friday bar is folded into the week of 2026-09-21; the weekly bar (first row) wins
        $this->assertDatabaseHas('weekly_prices', ['holding_id' => $us->id, 'week_date' => '2026-09-21', 'close' => 229.0, 'volume' => 5000]);
        $this->assertDatabaseMissing('weekly_prices', ['holding_id' => $us->id, 'week_date' => '2026-09-25']);

        // Assert: index_weekly_prices
        expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(30);
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'nikkei225', 'week_date' => '2026-09-21', 'close' => 42900.0]);
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'sp500', 'week_date' => '2026-09-21', 'close' => 6580.0]);

        // Regression: existing take-profit signal behaviour is unchanged
        expect(Signal::where('holding_snapshot_id', $jpHoldingSnapshot->id)->where('signal_type', 'bollinger_overheat')->count())->toBe(1);
        expect(TechnicalIndicator::where('holding_id', $jp->id)->exists())->toBeTrue();
        expect(TechnicalIndicator::where('holding_id', $us->id)->exists())->toBeTrue();
    });

    test('同じデータで分析処理を2回実行しても週次価格履歴・指数週次価格履歴は重複しない', function () {
        // Arrange
        [$batch, $snapshot] = uc014ImportBatch();
        $jp = uc014Holding('7203', 'jp');
        uc014HoldingSnapshot($snapshot, $jp, 10.0);
        uc014BindFakes(jp: ['7203' => uc014Weekly('2026-09-20', uc014Rising(1000.0, 30))]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);
        uc014BindFakes(jp: ['7203' => uc014Weekly('2026-09-20', uc014Rising(1000.0, 30))]);
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(WeeklyPrice::where('holding_id', $jp->id)->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(30);
    });

    test('価格取得に失敗した銘柄は週次価格履歴が保存されず、他の銘柄の週次価格履歴とシグナルは従来どおり保存される', function () {
        // Arrange
        [$batch, $snapshot] = uc014ImportBatch();
        $failing = uc014Holding('9999', 'jp', '取得失敗銘柄');
        uc014HoldingSnapshot($snapshot, $failing, 30.0);
        $ok = uc014Holding('1234', 'jp', '正常銘柄');
        $okHoldingSnapshot = uc014HoldingSnapshot($snapshot, $ok, 25.0);

        uc014BindFakes(
            jp: ['1234' => uc014Weekly('2026-09-20', uc014OverheatCloses(), 1000)],
            jpThrowsFor: ['9999'],
        );

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(WeeklyPrice::where('holding_id', $failing->id)->count())->toBe(0);
        expect(WeeklyPrice::where('holding_id', $ok->id)->count())->toBe(20);
        expect(Signal::where('holding_snapshot_id', $okHoldingSnapshot->id)->where('signal_type', 'bollinger_overheat')->count())->toBe(1);
        expect(IndexWeeklyPrice::count())->toBe(60);
    });
    test('保有銘柄の週次価格履歴の保存がDBエラーで失敗しても、その銘柄の利確シグナル・買いシグナル・テクニカル指標は従来どおり作成され例外は投げられない（UC-014エラーケース）', function () {
        // Arrange: two holdings with the SAME closes. For the failing one the
        // oldest bar carries a negative volume, which MySQL strict mode rejects
        // for weekly_prices.volume (unsignedBigInteger) — a real DB-level
        // failure inside the real WeeklyPriceRecorder's upsert, caught by its
        // try/catch. That bar is outside the last 20 weeks, so indicator /
        // signal inputs (closes, volume_ma20) are identical for both holdings.
        // (No Schema DDL here: on MySQL it would implicitly commit the
        // RefreshDatabase transaction.)
        Log::spy();
        [$batch, $snapshot] = uc014ImportBatch();
        $failing = uc014Holding('9101', 'jp', '週次保存失敗銘柄');
        $failingHoldingSnapshot = uc014HoldingSnapshot($snapshot, $failing, 25.0);
        $control = uc014Holding('9102', 'jp', '対照銘柄');
        $controlHoldingSnapshot = uc014HoldingSnapshot($snapshot, $control, 25.0);

        $closes = array_merge([100.0], uc014OverheatCloses()); // 21 weeks
        $controlHistory = uc014Weekly('2026-09-20', $closes, 1000);
        $failingHistory = $controlHistory;
        $failingHistory[0]['volume'] = -1;

        uc014BindFakes(jp: ['9101' => $failingHistory, '9102' => $controlHistory]);

        // Act (reaching the assertions means no exception escaped)
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert: the failing holding's weekly prices were not saved, the control's were
        expect(WeeklyPrice::where('holding_id', $failing->id)->count())->toBe(0);
        expect(WeeklyPrice::where('holding_id', $control->id)->count())->toBe(21);
        Log::shouldHaveReceived('warning')->atLeast()->once();

        // Assert: existing analysis outputs are unaffected for the failing holding
        expect(Signal::where('holding_snapshot_id', $failingHoldingSnapshot->id)->where('signal_type', 'bollinger_overheat')->count())->toBe(1);
        expect(TechnicalIndicator::where('holding_id', $failing->id)->exists())->toBeTrue();
        expect(BuySignal::where('holding_snapshot_id', $failingHoldingSnapshot->id)->pluck('signal_type')->sort()->values()->all())
            ->toBe(BuySignal::where('holding_snapshot_id', $controlHoldingSnapshot->id)->pluck('signal_type')->sort()->values()->all());
        expect(Signal::where('holding_snapshot_id', $failingHoldingSnapshot->id)->pluck('signal_type')->sort()->values()->all())
            ->toBe(Signal::where('holding_snapshot_id', $controlHoldingSnapshot->id)->pluck('signal_type')->sort()->values()->all());

        // Assert: index recording is independent and still succeeds
        expect(IndexWeeklyPrice::count())->toBe(60);
    });
});

describe('UC-014 週次価格履歴の保存（ウォッチリスト一括更新: RefreshWatchlistMarketDataAction）', function () {
    test('未保有のウォッチリスト銘柄の週足と、日経平均・S&P500の週足が保存される', function () {
        // Arrange
        $holding = uc014Holding('8888', 'jp', 'ウォッチ銘柄');
        WatchlistItem::create([
            'holding_id' => $holding->id,
            'folder_name' => 'テーマ',
            'exchange_label' => '東Ｐ',
            'source' => 'rakuten_favorites_csv',
            'last_seen_in_csv_at' => now(),
            'registered_at' => now(),
        ]);

        $history = uc014Weekly('2026-09-20', uc014Rising(1000.0, 60));
        $history[] = ['date' => '2026-09-25', 'close' => 1300.0, 'volume' => 999];
        uc014BindFakes(jp: ['8888' => $history]);

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert
        expect(WeeklyPrice::where('holding_id', $holding->id)->count())->toBe(60);
        $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-21', 'close' => 1295.0, 'volume' => 100000]);
        expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(30);
    });
});
