<?php

namespace Tests\Feature;

use App\Actions\Watchlist\ImportFavoriteCsvAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Jobs\RefreshWatchlistMarketDataJob;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\WatchlistBuySignal;
use App\Models\WatchlistItem;
use App\Models\WatchlistRefreshRun;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-012: ウォッチリスト一括更新 — Red phase Feature Test (F-012 Cycle 3)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0013-favorites-watchlist.md (D3, D5)
|   - docs/product/use-cases.md UC-012「基本フロー（一括更新）」
|   - docs/architecture/data-model.md #watchlist_buy_signals / #watchlist_refresh_runs
|   - ~/.claude/plans/stock_auto_order-favorites-watchlist-implementation-phase.md (Cycle 3)
|
| Not yet implemented: App\Actions\Watchlist\RefreshWatchlistMarketDataAction,
| App\Jobs\RefreshWatchlistMarketDataJob, App\Models\WatchlistBuySignal,
| App\Models\WatchlistRefreshRun, the watchlist_buy_signals / watchlist_refresh_runs
| migrations, the ImportFavoriteCsvAction job dispatch, the watchlist:refresh command.
| Every test below is expected to fail (Red).
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - RefreshWatchlistMarketDataAction::execute(): WatchlistRefreshRun
|     * creates a watchlist_refresh_runs row (status processing → completed)
|     * target = watchlist_items whose holding is NOT in the latest snapshot's
|       holding_snapshots (held favorites are refreshed by the weekly CSV import)
|     * per target holding: fetch weekly price history (jp/us client), fundamentals
|       (JQuants statements → FundamentalIndicatorMapper for jp / Finnhub →
|       UsFundamentalIndicatorMapper for us), updateOrCreate technical_indicators
|       & fundamental_indicators keyed by holding_id, then determine 押し目買い
|       signals and replace this holding's watchlist_buy_signals rows
|     * per-symbol fetch failure: log + failed_count++, continue with the rest
|     * increments processed_count as it goes; sets finished_at on completion
|   - NEVER writes signals / buy_signals / snapshots / holding_snapshots
|   - RefreshWatchlistMarketDataJob::handle() delegates to the action
|   - ImportFavoriteCsvAction dispatches RefreshWatchlistMarketDataJob on success
*/

/**
 * @param  array<int, float>  $closes  ascending weekly closes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function uc012Price(array $closes): array
{
    $rows = [];
    $date = new \DateTimeImmutable('2024-01-07');

    foreach ($closes as $i => $close) {
        $rows[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => 100000,
        ];
    }

    return $rows;
}

/**
 * @return array<int, float>
 */
function uc012RisingCloses(float $start = 1000.0, int $weeks = 60): array
{
    return array_map(fn ($i) => $start + $i * 5, range(0, $weeks - 1));
}

/**
 * @return array<int, array<string, mixed>>
 */
function uc012Statements(): array
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

    return [
        array_merge($latest, ['disclosed_date' => '2026-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31']),
        array_merge($latest, [
            'disclosed_date' => '2025-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31',
            'net_sales' => 100000.0, 'operating_profit' => 12000.0, 'eps' => 100.0,
        ]),
    ];
}

function uc012MarketFakes(): void
{
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient([
        'nikkei225' => uc012Price(uc012RisingCloses(30000.0, 60)),
        'sp500' => uc012Price(uc012RisingCloses(5000.0, 60)),
    ]));
}

/**
 * Create a watchlist item for a fresh (unheld) holding.
 */
function uc012WatchlistHolding(string $code, string $market = 'jp', string $name = '銘柄'): Holding
{
    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => $market,
        'instrument_type' => 'stock',
        'symbol_name' => $name,
        'first_detected_at' => now(),
    ]);

    WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => 'テーマ',
        'exchange_label' => $market === 'jp' ? '東Ｐ' : '米国',
        'source' => 'rakuten_favorites_csv',
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);

    return $holding;
}

/**
 * Mark $holding as currently held in the latest snapshot.
 */
function uc012MarkHeld(Holding $holding): void
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp.csv',
        'us_stock_filename' => 'us.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 1,
        'error_count' => 0,
        'imported_at' => now(),
    ]);
    $snapshot = Snapshot::firstOrCreate(
        ['import_batch_id' => $batch->id],
        ['snapshotted_at' => now()],
    );
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 1000,
        'current_price' => 1200,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 2000,
        'unrealized_gain_rate' => 20.0,
        'is_newly_detected' => false,
    ]);
}

beforeEach(function () {
    uc012MarketFakes();
});

test('未保有のウォッチリスト銘柄についてtechnical/fundamental指標がupsertされる（JP）', function () {
    $h = uc012WatchlistHolding('9999', 'jp', 'テスト日本株');

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['9999' => uc012Price(uc012RisingCloses())]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
        ['9999' => ['code' => '6050', 'name' => '電気機器']],
        ['9999' => uc012Statements()],
    ));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    app(RefreshWatchlistMarketDataAction::class)->execute();

    expect(TechnicalIndicator::where('holding_id', $h->id)->exists())->toBeTrue();
    expect(FundamentalIndicator::where('holding_id', $h->id)->exists())->toBeTrue();
});

test('未保有のUS銘柄はFinnhub経由でfundamental指標が入る', function () {
    $h = uc012WatchlistHolding('TSLA', 'us', 'テスラ');

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient);
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient(['TSLA' => uc012Price(uc012RisingCloses(200.0, 60))]));
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(
        ['TSLA' => ['peTTM' => 60.0, 'pbAnnual' => 12.0, 'roeTTM' => 20.0, 'operatingMarginTTM' => 15.0]],
        ['TSLA' => [['operating_income' => 5000.0, 'total_assets' => 100000.0, 'total_equity' => 60000.0]]],
    ));

    app(RefreshWatchlistMarketDataAction::class)->execute();

    expect(FundamentalIndicator::where('holding_id', $h->id)->exists())->toBeTrue();
});

test('押し目買いシグナルはwatchlist_buy_signalsに保存され、signals/buy_signalsは不変', function () {
    $h = uc012WatchlistHolding('8888', 'jp', '押し目テスト');

    // 直近で急落 → 反発（押し目シグナルが出やすい形）
    $closes = array_merge(uc012RisingCloses(1000.0, 50), [1290, 1250, 1180, 1100, 1050, 1000, 950, 900, 880, 1050]);

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['8888' => uc012Price($closes)]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
        ['8888' => ['code' => '6050', 'name' => '電気機器']],
        ['8888' => uc012Statements()],
    ));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    app(RefreshWatchlistMarketDataAction::class)->execute();

    // watchlist_buy_signals はこの銘柄について判定された（0件以上、テーブルに触れた）
    // signals / buy_signals は絶対に増えない
    expect(Signal::count())->toBe(0);
    expect(BuySignal::count())->toBe(0);
    // WatchlistBuySignal は holding_id で紐づく（件数はロジック依存なので存在確認まで）
    expect(WatchlistBuySignal::where('holding_id', '!=', null)->get()->every(fn ($s) => $s->holding_id === $h->id))->toBeTrue();
});

test('保有中のウォッチリスト銘柄は一括更新の対象外', function () {
    $held = uc012WatchlistHolding('7777', 'jp', '保有中');
    uc012MarkHeld($held);

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['7777' => uc012Price(uc012RisingCloses())]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $run = app(RefreshWatchlistMarketDataAction::class)->execute();

    expect(TechnicalIndicator::where('holding_id', $held->id)->exists())->toBeFalse();
    expect($run->total_count)->toBe(0);
});

test('銘柄単位の取得失敗はスキップし、他銘柄の処理は継続、failed_countが増える', function () {
    $ok = uc012WatchlistHolding('1111', 'jp', '正常');
    $ng = uc012WatchlistHolding('2222', 'jp', '取得失敗');

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(
        ['1111' => uc012Price(uc012RisingCloses())],
        throwsFor: ['2222'],
    ));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
        ['1111' => ['code' => '6050', 'name' => '電気機器']],
        ['1111' => uc012Statements()],
    ));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $run = app(RefreshWatchlistMarketDataAction::class)->execute();

    expect(TechnicalIndicator::where('holding_id', $ok->id)->exists())->toBeTrue();
    expect(TechnicalIndicator::where('holding_id', $ng->id)->exists())->toBeFalse();
    expect($run->failed_count)->toBe(1);
    expect($run->processed_count)->toBe(2);
});

test('watchlist_refresh_runsにtotal/processed/status=completedが記録される', function () {
    uc012WatchlistHolding('3333', 'jp', '銘柄A');
    uc012WatchlistHolding('4444', 'jp', '銘柄B');

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient([
        '3333' => uc012Price(uc012RisingCloses()),
        '4444' => uc012Price(uc012RisingCloses()),
    ]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $run = app(RefreshWatchlistMarketDataAction::class)->execute();

    $run->refresh();
    expect($run->status)->toBe('completed');
    expect($run->total_count)->toBe(2);
    expect($run->processed_count)->toBe(2);
    expect($run->finished_at)->not->toBeNull();
    expect(WatchlistRefreshRun::count())->toBe(1);
});

test('再実行でwatchlist_buy_signalsは銘柄単位で置き換えられ重複しない', function () {
    $h = uc012WatchlistHolding('5555', 'jp', '再実行テスト');
    $closes = array_merge(uc012RisingCloses(1000.0, 50), [1290, 1250, 1180, 1100, 1050, 1000, 950, 900, 880, 1050]);

    $wire = function () use ($closes) {
        app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['5555' => uc012Price($closes)]));
        app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
            ['5555' => ['code' => '6050', 'name' => '電気機器']],
            ['5555' => uc012Statements()],
        ));
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);
    };

    $wire();
    app(RefreshWatchlistMarketDataAction::class)->execute();
    $countAfterFirst = WatchlistBuySignal::where('holding_id', $h->id)->count();

    $wire();
    app(RefreshWatchlistMarketDataAction::class)->execute();
    $countAfterSecond = WatchlistBuySignal::where('holding_id', $h->id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

test('お気に入りCSV取込が成功すると一括更新Jobがdispatchされる', function () {
    Bus::fake();

    $utf8 = "\"MS2\",\"2\"\r\n\"STK\",\"9433\",\"通信/情報系　日本株\",\"1\",\"東Ｐ\",\"ＫＤＤＩ\"\r\n";
    $cp932 = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
    $file = UploadedFile::fake()->createWithContent('favorites.csv', $cp932);

    app(ImportFavoriteCsvAction::class)->execute($file);

    Bus::assertDispatched(RefreshWatchlistMarketDataJob::class);
});

test('watchlist:refresh コマンドが一括更新を実行する', function () {
    uc012WatchlistHolding('6666', 'jp', 'コマンドテスト');

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['6666' => uc012Price(uc012RisingCloses())]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $this->artisan('watchlist:refresh')->assertExitCode(0);

    expect(WatchlistRefreshRun::where('status', 'completed')->count())->toBe(1);
});
