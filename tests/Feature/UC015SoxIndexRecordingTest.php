<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\IndexWeeklyPrice;
use App\Models\MarketIndicatorSnapshot;
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
| UC-015: 集中度ダッシュボード — SOX指数の週足の記録（Red phase, CHG-0026 Cycle1）
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0019-concentration-dashboard.md D2
|   - docs/product/use-cases.md UC-015 基本フロー（記録）1, エラーケース
|       「CSV取込時のSOX指数の取得に失敗 → 取込の分析処理は継続する。失敗はログに記録する」
|   - docs/architecture/data-model.md `index_weekly_prices`
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - index_weekly_prices.index_name enum accepts 'sox' (nikkei225/sp500 unchanged).
|   - FetchExternalMarketDataAction::execute() additionally fetches 'sox' and
|     records it via WeeklyPriceRecorder::recordIndex('sox', ...).
|   - A 'sox' fetch failure is caught and logged with Log::warning (context
|     mentions 'sox'); the rest of the analysis continues unchanged.
|   - SOX is never written to market_indicator_snapshots (UC-007 enum).
|   - RefreshWatchlistMarketDataAction never fetches/records 'sox'.
|
*/

/**
 * Weekly bars ending on $lastDate, stepping back 7 days per bar.
 *
 * @param  array<int, float>  $closes  ascending weekly closes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function uc015Weekly(string $lastDate, array $closes, int $volume = 100000): array
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
function uc015Rising(float $start, int $weeks, float $step = 5.0): array
{
    return array_map(fn (int $i) => $start + $i * $step, range(0, $weeks - 1));
}

/**
 * 19 flat weeks then a spike — produces bollinger_overheat.
 *
 * @return array<int, float>
 */
function uc015OverheatCloses(): array
{
    return array_merge(array_fill(0, 19, 100.0), [130.0]);
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function uc015ImportBatch(): array
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

function uc015Holding(string $code, string $market, string $name = '銘柄'): Holding
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

function uc015HoldingSnapshot(Snapshot $snapshot, Holding $holding, float $unrealizedGainRate): HoldingSnapshot
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
 * sp500 / sox: US-style Monday dates (30 weeks ending Mon 2026-09-21).
 * ^SOX reports volume 0 on every row (ADR-0019 D2).
 *
 * @return array<string, array<int, array{date: string, close: float, volume: int}>>
 */
function uc015IndexHistories(bool $withSox = true): array
{
    $histories = [
        'nikkei225' => uc015Weekly('2026-09-20', uc015Rising(40000.0, 30, 100.0), 0),
        'sp500' => uc015Weekly('2026-09-21', uc015Rising(6000.0, 30, 20.0), 0),
    ];

    if ($withSox) {
        $histories['sox'] = uc015Weekly('2026-09-21', uc015Rising(5000.0, 30, 10.0), 0);
    }

    return $histories;
}

/**
 * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $jp
 * @param  array<int, string>  $indexThrowsFor
 */
function uc015BindFakes(array $jp = [], array $indexThrowsFor = []): FakeMarketIndexClient
{
    $marketIndexClient = new FakeMarketIndexClient(uc015IndexHistories(), $indexThrowsFor);

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient($jp));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, $marketIndexClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    return $marketIndexClient;
}

describe('UC-015 SOX指数の週足の保存先（index_weekly_prices）', function () {
    test('UC-015: 指数週次価格履歴にindex_name=soxの行を保存でき、既存のnikkei225/sp500も従来どおり保存できる', function () {
        // Arrange / Act
        IndexWeeklyPrice::create(['index_name' => 'sox', 'week_date' => '2026-09-21', 'close' => 5290.0]);
        IndexWeeklyPrice::create(['index_name' => 'nikkei225', 'week_date' => '2026-09-21', 'close' => 42900.0]);
        IndexWeeklyPrice::create(['index_name' => 'sp500', 'week_date' => '2026-09-21', 'close' => 6580.0]);

        // Assert
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'sox', 'week_date' => '2026-09-21', 'close' => 5290.0]);
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'nikkei225', 'week_date' => '2026-09-21']);
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'sp500', 'week_date' => '2026-09-21']);
    });
});

describe('UC-015 SOX指数の週足の記録（CSV取込の分析処理: FetchExternalMarketDataAction）', function () {
    test('UC-015: CSV取込の分析処理で日経平均・S&P500に加えてSOX指数の週足が取得され、週次価格履歴として保存される', function () {
        // Arrange
        [$batch, $snapshot] = uc015ImportBatch();
        $jp = uc015Holding('7203', 'jp', 'トヨタ自動車');
        uc015HoldingSnapshot($snapshot, $jp, 10.0);
        $marketIndexClient = uc015BindFakes(jp: ['7203' => uc015Weekly('2026-09-20', uc015Rising(1000.0, 30))]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert: SOX was requested
        expect($marketIndexClient->requestedIndexNames)->toContain('sox');

        // Assert: SOX weekly bars saved (Monday week_date, volume-0 rows kept)
        expect(IndexWeeklyPrice::where('index_name', 'sox')->count())->toBe(30);
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'sox', 'week_date' => '2026-09-21', 'close' => 5290.0]);
        $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'sox', 'week_date' => '2026-03-02', 'close' => 5000.0]);

        // Regression: nikkei225 / sp500 recording unchanged
        expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(30);
    });

    test('UC-015: SOX指数の取得に失敗しても取込の分析処理は継続し、日経平均・S&P500の週足と保有銘柄のシグナル・テクニカル指標は従来どおり保存され、失敗は警告ログに記録される', function () {
        // Arrange
        Log::spy();
        [$batch, $snapshot] = uc015ImportBatch();
        $jp = uc015Holding('7203', 'jp', 'トヨタ自動車');
        $jpHoldingSnapshot = uc015HoldingSnapshot($snapshot, $jp, 25.0); // >20% -> take-profit判定対象
        $marketIndexClient = uc015BindFakes(
            jp: ['7203' => uc015Weekly('2026-09-20', uc015OverheatCloses(), 1000)],
            indexThrowsFor: ['sox'],
        );

        // Act (reaching the assertions means no exception escaped)
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert: SOX was attempted, failed, and nothing was saved for it
        expect($marketIndexClient->requestedIndexNames)->toContain('sox');
        expect(IndexWeeklyPrice::where('index_name', 'sox')->count())->toBe(0);

        // Assert: the failure is logged as a warning that identifies SOX
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains(json_encode([$message, $context]), 'sox'))
            ->atLeast()->once();

        // Assert: nikkei225 / sp500 still recorded, market indicators still saved
        expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(30);
        expect(MarketIndicatorSnapshot::where('snapshot_id', $snapshot->id)->count())->toBe(2);

        // Assert: holding analysis unaffected
        expect(WeeklyPrice::where('holding_id', $jp->id)->count())->toBe(20);
        expect(TechnicalIndicator::where('holding_id', $jp->id)->exists())->toBeTrue();
        expect(Signal::where('holding_snapshot_id', $jpHoldingSnapshot->id)->where('signal_type', 'bollinger_overheat')->count())->toBe(1);
    });

    test('UC-015: SOX指数は市場指標スナップショットには保存されず、スナップショットの市場指標は日経平均・S&P500のみのまま', function () {
        // Arrange
        [$batch, $snapshot] = uc015ImportBatch();
        $jp = uc015Holding('7203', 'jp', 'トヨタ自動車');
        uc015HoldingSnapshot($snapshot, $jp, 10.0);
        uc015BindFakes(jp: ['7203' => uc015Weekly('2026-09-20', uc015Rising(1000.0, 30))]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(MarketIndicatorSnapshot::where('snapshot_id', $snapshot->id)->pluck('index_name')->sort()->values()->all())
            ->toBe(['nikkei225', 'sp500']);
    });
});

describe('UC-015 SOX指数はウォッチリスト一括更新では取得しない（RefreshWatchlistMarketDataAction）', function () {
    test('UC-015: ウォッチリスト一括更新ではSOX指数の週足を取得・保存せず、日経平均・S&P500の週足は従来どおり保存される', function () {
        // Arrange
        $holding = uc015Holding('8888', 'jp', 'ウォッチ銘柄');
        WatchlistItem::create([
            'holding_id' => $holding->id,
            'folder_name' => 'テーマ',
            'exchange_label' => '東Ｐ',
            'source' => 'rakuten_favorites_csv',
            'last_seen_in_csv_at' => now(),
            'registered_at' => now(),
        ]);
        $marketIndexClient = uc015BindFakes(jp: ['8888' => uc015Weekly('2026-09-20', uc015Rising(1000.0, 60))]);

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert
        expect($marketIndexClient->requestedIndexNames)->not->toContain('sox');
        expect(IndexWeeklyPrice::where('index_name', 'sox')->count())->toBe(0);
        expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(30);
        expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(30);
    });
});
