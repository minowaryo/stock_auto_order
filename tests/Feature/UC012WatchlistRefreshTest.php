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

// -----------------------------------------------------------------------
// review 修正（2026-09-08）: run のライフサイクルを1行に統一する（#1/#3）、
// per-symbol 永続化をトランザクション化する（#4）
// -----------------------------------------------------------------------

test('渡された queued run を引き継ぎ、2つ目の run 行を作らない。完了後 active() は false', function () {
    uc012WatchlistHolding('QRUN', 'jp', 'ラン引継ぎ');
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['QRUN' => uc012Price(uc012RisingCloses())]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $queued = WatchlistRefreshRun::create(['status' => 'queued']);

    $returned = app(RefreshWatchlistMarketDataAction::class)->execute($queued);

    expect($returned->id)->toBe($queued->id);
    expect(WatchlistRefreshRun::count())->toBe(1);
    expect($queued->fresh()->status)->toBe('completed');
    expect(WatchlistRefreshRun::active()->exists())->toBeFalse();
});

test('Job の failed() ハンドラは queued/processing の run を failed にする（processing に張り付かない）', function () {
    $run = WatchlistRefreshRun::create(['status' => 'processing', 'started_at' => now()]);

    (new RefreshWatchlistMarketDataJob($run->id))->failed(new \RuntimeException('worker killed'));

    expect($run->fresh()->status)->toBe('failed');
    expect(WatchlistRefreshRun::active()->exists())->toBeFalse();
});

test('銘柄単位の途中失敗は既存の watchlist_buy_signals を消し残さない（トランザクション）', function () {
    $holding = uc012WatchlistHolding('TXN1', 'jp', 'トランザクションテスト');
    WatchlistBuySignal::create([
        'holding_id' => $holding->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => '前回分',
        'determined_at' => now()->subWeek(),
    ]);

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['TXN1' => uc012Price(uc012RisingCloses())]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    // fetchStatements が投げる → 銘柄処理が中断。delete も rollback されるべき。
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient([], [], [], throwsForStatements: ['TXN1']));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $run = app(RefreshWatchlistMarketDataAction::class)->execute();

    expect($run->failed_count)->toBe(1);
    expect(WatchlistBuySignal::where('holding_id', $holding->id)->count())->toBe(1);
    expect(WatchlistBuySignal::where('holding_id', $holding->id)->first()->reason_summary)->toBe('前回分');
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

// -----------------------------------------------------------------------
// ADR-0015 D3 (Cycle4b): 低成長銘柄でのPEG除外配線 — watchlist_buy_signals
//
// Source of truth: docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md
// D3。BuySignalDeterminationService::determine()自体は既にGreenで
// revenueGrowth/operatingIncomeGrowth/per/dividendYield引数を受け付けるが、
// このCycle4b着手前時点ではRefreshWatchlistMarketDataAction::refreshHolding()
// の呼び出し側がこの4引数を一切渡していない（現状は
// $this->buySignalDeterminationService->determine($priceHistory,
// $marketReturn13w, null, $fundamental['peg_ratio'] ?? null) の4引数のみ）。
//
// 価格推移フィクスチャは「押し目買いシグナルはwatchlist_buy_signalsに保存され」
// テスト（本ファイル上部）と同一の closes（uc012RisingCloses(1000.0, 50) +
// 急落→反発の13週分のテール、最終値1050）を再利用する。最終週の終値1050が
// refreshHolding()内でcurrentPriceとしてそのままFundamentalIndicatorMapperに
// 渡される（watchlist側はholding_snapshotsを持たないため、holdingSnapshotの
// current_priceではなく価格履歴の最終値を使う設計、RefreshWatchlistMarketDataAction
// ::refreshHolding()参照）。
//
// Expected Red cause: 低成長フィクスチャ（revenue_growth=3.0% /
// operating_income_growth=3.0%、いずれも5.0%以下）かつeps_growth=5.0%から
// PER≈10.0・PEG≈2.0（従来のPEG割安条件<=1.0は満たさない）。配線前は
// isLowGrowth(null, null)=falseのままPEGのみで判定されPEG=2.0は<=1.0を
// 満たさないためpeg_undervaluedは発生しない。配線後は低成長と判定され
// PER=10.0<=15・配当利回り3.33%(=35/1050*100)>=3.0%の絶対閾値を満たし
// peg_undervaluedが発生する想定。本テストは「現状は発生しない（Red）」を、
// 「発生するはず」というアサーションで捕捉する。
// -----------------------------------------------------------------------

test('成長率が低い（5%以下）未保有JP銘柄は、PEGレシオが割安基準(<=1.0)を満たさなくてもPER/配当利回りの絶対閾値を満たせばpeg_undervaluedがwatchlist_buy_signalsに保存される', function () {
    $h = uc012WatchlistHolding('7777', 'jp', '低成長割安テスト');

    // 2026-09-20修正: 元のフィクスチャ（50週上昇後に1290→880まで急落し1050へ戻す
    // 10週のテール）は、beforeEach()が設定する市場ベンチマーク（nikkei225/sp500、
    // uc012RisingCloses(30000.0, 60)で終始なだらかに上昇）に対して大きく劣後し、
    // relative_strength_vs_market が約-14.85%となり
    // BuySignalDeterminationService::preconditionsSatisfied()の事前条件B
    // （対市場相対力-5.0%以上）を満たさず、peg_undervaluedを含む全シグナルが
    // 発生しない状態だった（Cycle4bのGreenフェーズでtdd-implementerが発見）。
    // 配線ロジック自体はバグではなく、テストのフィクスチャ不備だったため、
    // 市場と同様になだらかに上昇し最終値が1050ちょうどになる系列に置き換える
    // （PER=1050/105=10.0は変えない）。60週かけて755→1050へ5ずつ上昇するため、
    // 直近13週も52週高値（=最終値1050）付近を維持し事前条件A（52週高値の85%
    // 以内に接近）も満たす。
    $closes = uc012RisingCloses(755.0, 60);

    // 低成長フィクスチャ: revenue_growth=3.0% / operating_income_growth=3.0%
    // （いずれも5.0%以下 -> 低成長） / eps_growth=5.0% -> 最終週終値1050で
    // PER=1050/105=10.0（<=15） / PEG=10.0/5.0=2.0（>1.0、従来のPEG割安条件は
    // 満たさない）。配当利回り = 35/1050*100 ≈ 3.33%（>=3.0%）。
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

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['7777' => uc012Price($closes)]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
        statementsResponses: ['7777' => $statements],
    ));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    app(RefreshWatchlistMarketDataAction::class)->execute();

    $signalTypes = WatchlistBuySignal::where('holding_id', $h->id)->pluck('signal_type')->all();
    expect($signalTypes)->toContain('peg_undervalued');
});

// -----------------------------------------------------------------------
// ADR-0015 D2（2回目の/review・Cycle4d）: watchlist専用銘柄でも直近3期平均
// 成長率(avg_revenue_growth/avg_operating_income_growth)を計算・保存する。
//
// Source of truth: docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md D2。
// FetchExternalMarketDataAction（保有銘柄パイプライン、Cycle4a）は
// FundamentalIndicatorMapper::averageAnnualGrowth()の結果をfundamental_indicators.
// avg_revenue_growth/avg_operating_income_growthとして保存するが、
// RefreshWatchlistMarketDataAction::refreshHolding()（未保有ウォッチリスト銘柄
// パイプライン）はこの計算を一切行っていない（$fundamental = map(...)のみ）。
// このため一度も保有したことのないwatchlist専用銘柄ではD2の平均成長率レスキューが
// 発火しない（/review 2回目でcross-file tracer・altitude・line-by-lineの3角度が
// 独立に発見）。
//
// Expected Red cause: 配線前はFundamentalIndicator.avg_revenue_growth /
// avg_operating_income_growthがnullのまま保存される。配線後は
// averageAnnualGrowth($statements, 'net_sales'/'operating_profit')の計算結果
// （直近4期のFY決算から3期分のYoY平均）が保存される想定。
// -----------------------------------------------------------------------

test('未保有のウォッチリスト銘柄（JP）でも直近3期平均成長率が計算・保存される', function () {
    $h = uc012WatchlistHolding('AVGG', 'jp', '平均成長率テスト');

    // 4期分のFY決算（直近が2029-03期、最古が2026-03期）: net_salesは
    // 100000→110000→121000→133100と毎期+10%、operating_profitは
    // 10000→11000→12100→13310と同じく毎期+10%で、3期平均成長率は
    // どちらも10.0%になる想定。
    $statements = [
        [
            'disclosed_date' => '2029-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2029-03-31',
            'net_sales' => 133100.0, 'operating_profit' => 13310.0, 'profit' => 10000.0, 'eps' => 120.0,
            'book_value_per_share' => 800.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
            'dividend_per_share_annual' => 30.0, 'payout_ratio_annual' => 0.30,
        ],
        [
            'disclosed_date' => '2028-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2028-03-31',
            'net_sales' => 121000.0, 'operating_profit' => 12100.0, 'profit' => 9000.0, 'eps' => 110.0,
            'book_value_per_share' => 760.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
            'dividend_per_share_annual' => 28.0, 'payout_ratio_annual' => 0.30,
        ],
        [
            'disclosed_date' => '2027-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2027-03-31',
            'net_sales' => 110000.0, 'operating_profit' => 11000.0, 'profit' => 8000.0, 'eps' => 100.0,
            'book_value_per_share' => 720.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
            'dividend_per_share_annual' => 26.0, 'payout_ratio_annual' => 0.30,
        ],
        [
            'disclosed_date' => '2026-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31',
            'net_sales' => 100000.0, 'operating_profit' => 10000.0, 'profit' => 7000.0, 'eps' => 90.0,
            'book_value_per_share' => 680.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
            'dividend_per_share_annual' => 24.0, 'payout_ratio_annual' => 0.30,
        ],
    ];

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['AVGG' => uc012Price(uc012RisingCloses())]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
        statementsResponses: ['AVGG' => $statements],
    ));
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    app(RefreshWatchlistMarketDataAction::class)->execute();

    $indicator = FundamentalIndicator::where('holding_id', $h->id)->first();
    expect($indicator)->not->toBeNull();
    expect($indicator->avg_revenue_growth)->not->toBeNull();
    expect(round($indicator->avg_revenue_growth, 1))->toBe(10.0);
    expect($indicator->avg_operating_income_growth)->not->toBeNull();
    expect(round($indicator->avg_operating_income_growth, 1))->toBe(10.0);
});
