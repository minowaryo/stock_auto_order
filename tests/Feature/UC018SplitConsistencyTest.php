<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\PriceTracking\TrackPriceHistoryAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\PriceTrackingTarget;
use App\Models\Snapshot;
use App\Models\StockSplit;
use App\Models\WatchlistItem;
use App\Models\WeeklyPrice;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClient;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\PriceHistory;
use App\Services\MarketData\UsStockPriceClient;
use App\Services\MarketData\UsStockPriceClientInterface;
use App\Services\MarketData\YahooFinanceChartClient;
use App\Services\PriceTracking\PriceTrackingTargetUpdater;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-018 株式分割の整合 — Red phase (CHG-0033 Cycle 6e)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0024-trade-and-signal-price-tracking.md D6（未解決事項）
|   - docs/architecture/data-model.md `price_tracking_targets.full_history_fetched_at`
|     と「分割の整合」（2026-10-10 Gate 3承認）
|
| Problem: the holdings / watchlist refresh saves only the last 104 weeks
| (range=2y) without splits. After a split those weeks are rewritten with
| adjusted closes while the backfilled older weeks keep pre-split closes.
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - Jp/UsStockPriceClientInterface::fetchWeeklyPriceHistoryWithSplits(
|       string $symbolCode): PriceHistory — the same request as
|     fetchWeeklyPriceHistory() (range=2y, interval=1wk, last 104 weeks,
|     ".T" for JP) plus events=splits. Non-2xx → FAILED with no rows; a
|     connection error still throws, exactly like fetchWeeklyPriceHistory()
|     (the refresh actions already catch and count it), so the refresh
|     behaves as before. One request per holding, as before.
|   - FetchExternalMarketDataAction and RefreshWatchlistMarketDataAction use
|     it: the rows feed the analysis exactly as before; the splits are
|     recorded with StockSplitRecorder. Test fakes accept $splits per symbol.
|   - price_tracking_targets.full_history_fetched_at is set to now() by an
|     ok 10-year fetch (applyFetch with backfill: true) whose rows were
|     verified as saved; a failed fetch leaves it unchanged.
|   - price:track (TrackPriceHistoryAction) also refetches 10 years for a
|     holding when: backfilled_from_week is set, status is not
|     'unavailable', and a stock_splits row was created after
|     COALESCE(full_history_fetched_at, price_tracking_targets.created_at).
|     It is fetched even when the 26-week tracking is completed / has no
|     deadline, and even when the refresh already saved its last confirmed
|     week. Summary: refetchedForSplits.
|
| Expected Red: the client method, the column, the selection rule and the
| summary field do not exist yet.
|
*/

/**
 * @param  list<int>  $timestamps
 * @param  list<float>  $closes
 * @param  array<string, mixed>|null  $events
 * @return array<string, mixed>
 */
function scChart(array $timestamps, array $closes, ?array $events = null): array
{
    $result = [
        'timestamp' => $timestamps,
        'indicators' => ['quote' => [['close' => $closes, 'volume' => array_fill(0, count($closes), 10)]]],
    ];

    if ($events !== null) {
        $result['events'] = $events;
    }

    return ['chart' => ['result' => [$result], 'error' => null]];
}

/**
 * A 2:1 split on 2026-09-30 (timestamp 1790726400), as Yahoo sends it.
 *
 * @return array<string, mixed>
 */
function scSplitEvent(): array
{
    return ['splits' => ['1790726400' => ['date' => 1790726400, 'numerator' => 2.0, 'denominator' => 1.0, 'splitRatio' => '2:1']]];
}

/**
 * Scripted 10-year fetch for price:track.
 */
class FakeSplitTrackClient implements PriceBackfillClientInterface
{
    /** @var list<string> */
    public array $requests = [];

    public function __construct(private readonly float $close = 50.0) {}

    public function fetchStock(string $market, string $symbolCode): PriceHistory
    {
        $this->requests[] = "{$market}:{$symbolCode}";

        return new PriceHistory(PriceHistory::OK, [
            ['date' => '2016-10-03', 'close' => $this->close, 'volume' => 1],
            ['date' => '2026-09-28', 'close' => $this->close, 'volume' => 1],
        ], [['date' => '2026-09-30', 'numerator' => 2, 'denominator' => 1]]);
    }

    public function fetchIndex(string $indexName): PriceHistory
    {
        $this->requests[] = "index:{$indexName}";

        return new PriceHistory(PriceHistory::OK, [['date' => '2026-09-28', 'close' => 1.0, 'volume' => 0]]);
    }

    /**
     * @return list<string>
     */
    public function stockRequests(): array
    {
        return array_values(array_filter($this->requests, fn (string $r) => ! str_starts_with($r, 'index:')));
    }
}

function scHolding(string $code): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

/**
 * A holding backfilled on $fetchedAt (10 years of pre-split closes), whose
 * 26-week tracking is over or has no deadline.
 */
function scBackfilled(string $code, ?string $fetchedAt = '2026-10-09 12:00:00', string $status = 'completed'): Holding
{
    $holding = scHolding($code);
    app(WeeklyPriceRecorder::class)->recordHolding($holding, [
        ['date' => '2016-10-03', 'close' => 100.0, 'volume' => 1],
        ['date' => '2026-09-28', 'close' => 100.0, 'volume' => 1],
    ]);
    PriceTrackingTarget::create([
        'holding_id' => $holding->id, 'status' => $status, 'track_until_week' => null,
        'latest_saved_week' => '2026-09-28', 'backfilled_from_week' => '2016-10-03', 'full_history_fetched_at' => $fetchedAt,
    ]);

    return $holding;
}

function scSplit(Holding $holding, string $createdAt): void
{
    StockSplit::create([
        'holding_id' => $holding->id, 'effective_date' => '2026-09-30',
        'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo',
    ])->forceFill(['created_at' => $createdAt])->save();
}

function scTrackClient(float $close = 50.0): FakeSplitTrackClient
{
    $client = new FakeSplitTrackClient($close);
    app()->instance(PriceBackfillClientInterface::class, $client);

    return $client;
}

function scRun()
{
    return app(TrackPriceHistoryAction::class)->execute();
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00'); // Wednesday; last confirmed week 2026-10-05
    Sleep::fake();
});

describe('UC-018 分割の整合: 保有・ウォッチリストの104週の取得で分割も受け取る', function () {
    test('国内株は従来と同じ2年・週足に分割イベントを足して1回だけ要求し、直近104週と分割を返す', function () {
        // Arrange: 106 weekly bars and a 2:1 split
        $timestamps = array_map(fn (int $i) => 1727654400 + $i * 604800, range(0, 105)); // from 2024-09-30
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(scChart($timestamps, array_fill(0, 106, 100.0), scSplitEvent()))]);

        // Act
        $history = (new JpStockPriceClient(new YahooFinanceChartClient))->fetchWeeklyPriceHistoryWithSplits('7203');

        // Assert
        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->url())->values();
        expect($sent)->toHaveCount(1);
        expect($sent[0])->toContain('/chart/7203.T')->toContain('range=2y')->toContain('interval=1wk')->toContain('events=splits');
        expect($history->status)->toBe(PriceHistory::OK);
        expect($history->rows)->toHaveCount(104);
        expect($history->splits)->toBe([['date' => '2026-09-30', 'numerator' => 2, 'denominator' => 1]]);
    });

    test('米国株はティッカーのまま要求し、HTTPの失敗は行なしの失敗として返す', function () {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response('Server Error', 500)]);

        // Act
        $history = (new UsStockPriceClient(new YahooFinanceChartClient))->fetchWeeklyPriceHistoryWithSplits('NVDA');

        // Assert
        expect(Http::recorded()->first()[0]->url())->toContain('/chart/NVDA')->toContain('events=splits');
        expect([$history->status, $history->rows])->toBe([PriceHistory::FAILED, []]);
    });

    test('接続エラーは、従来の取得と同じく例外になる（呼び出し側の失敗の扱いを変えない）', function () {
        // Arrange
        Http::fake(fn () => throw new ConnectionException('timeout'));

        // Act / Assert
        expect(fn () => (new JpStockPriceClient(new YahooFinanceChartClient))->fetchWeeklyPriceHistoryWithSplits('7203'))
            ->toThrow(ConnectionException::class);
    });

    test('CSV取込の分析処理（保有銘柄）で受け取った分割が記録される', function () {
        // Arrange
        $batch = ImportBatch::create([
            'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
            'mutual_fund_filename' => null, 'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
        ]);
        $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
        $holding = scHolding('9101');
        HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id, 'quantity' => 100, 'average_cost' => 800,
            'current_price' => 950, 'fx_rate_used' => null, 'unrealized_gain_amount' => 15000, 'unrealized_gain_rate' => 5.0,
            'ma20' => null, 'ma75' => null, 'is_newly_detected' => false,
        ]);
        scBindRefreshFakes('9101');

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        expect(StockSplit::where('holding_id', $holding->id)->sole()->ratio_numerator)->toBe(2);
        expect(WeeklyPrice::where('holding_id', $holding->id)->count())->toBeGreaterThan(0); // the rows are saved as before
    });

    test('ウォッチリストの一括更新で受け取った分割が記録される', function () {
        // Arrange
        $holding = scHolding('7777');
        WatchlistItem::create([
            'holding_id' => $holding->id, 'folder_name' => 'テーマ', 'exchange_label' => '東Ｐ',
            'source' => 'rakuten_favorites_csv', 'last_seen_in_csv_at' => now(), 'registered_at' => now(),
        ]);
        scBindRefreshFakes('7777');

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert
        expect(StockSplit::where('holding_id', $holding->id)->sole()->ratio_numerator)->toBe(2);
    });
});

describe('UC-018 分割の整合: 10年分を取得した日時', function () {
    test('10年分の取得に成功して保存を確認できたら取得日時が記録され、失敗では変わらない', function () {
        // Arrange
        $holding = scHolding('1111');
        $updater = app(PriceTrackingTargetUpdater::class);
        $rows = [['date' => '2016-10-03', 'close' => 1.0, 'volume' => 1], ['date' => '2026-09-28', 'close' => 1.0, 'volume' => 1]];
        app(WeeklyPriceRecorder::class)->recordHolding($holding, $rows);

        // Act
        $updater->applyFetch($holding, new PriceHistory(PriceHistory::OK, $rows), backfill: true);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $after = $updater->applyFetch($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 500'), backfill: true);

        // Assert
        expect($after->full_history_fetched_at->toDateTimeString())->toBe('2026-10-14 10:00:00');
    });
});

describe('UC-018 分割の整合: price:track が分割のあった銘柄を10年分取り直す', function () {
    test('追跡の終わった銘柄でも、最後に10年分を取った後に記録された分割があれば取り直し、古い週も分割後の値になる', function () {
        // Arrange: backfilled on 10-09, the refresh recorded a split on 10-12
        $holding = scBackfilled('1111');
        scSplit($holding, '2026-10-12 09:00:00');
        $client = scTrackClient(50.0);

        // Act
        $summary = scRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:1111']);
        expect((float) WeeklyPrice::where('holding_id', $holding->id)->where('week_date', '2016-10-03')->sole()->close)->toBe(50.0);
        $target = PriceTrackingTarget::where('holding_id', $holding->id)->sole();
        expect($target->status)->toBe('completed');
        expect($target->full_history_fetched_at->toDateTimeString())->toBe('2026-10-14 10:00:00');
        expect($summary->refetchedForSplits)->toBe(1);
    });

    test('取り直したあとは、同じ分割で再び取り直さない', function () {
        // Arrange
        $holding = scBackfilled('1111');
        scSplit($holding, '2026-10-12 09:00:00');
        scTrackClient();
        scRun();

        // Act
        Carbon::setTestNow('2026-10-14 11:00:00');
        $client = scTrackClient();
        scRun();

        // Assert
        expect($client->stockRequests())->toBe([]);
    });

    test('10年分を取得した時点ですでに記録されていた分割では、取り直さない', function () {
        // Arrange: the split was recorded by the backfill itself
        $holding = scBackfilled('1111', '2026-10-09 12:00:00');
        scSplit($holding, '2026-10-09 11:59:59');
        $client = scTrackClient();

        // Act
        scRun();

        // Assert
        expect($client->stockRequests())->toBe([]);
    });

    test('列を追加する前に取得した銘柄（取得日時が空）は、追跡状態の作成日時より後の分割で取り直す', function () {
        // Arrange
        Carbon::setTestNow('2026-10-09 12:00:00');
        $holding = scBackfilled('1111', null);
        scSplit($holding, '2026-10-09 11:00:00'); // before the row was created: already in the backfill
        $other = scBackfilled('2222', null);
        scSplit($other, '2026-10-12 09:00:00');   // after: a new split
        Carbon::setTestNow('2026-10-14 10:00:00');
        $client = scTrackClient();

        // Act
        scRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:2222']);
    });

    test('一括補完していない銘柄（104週しか持たない）と、取得不能の銘柄は、分割があっても取り直さない', function () {
        // Arrange
        $notBackfilled = scHolding('1111');
        PriceTrackingTarget::create(['holding_id' => $notBackfilled->id, 'status' => 'completed', 'full_history_fetched_at' => null]);
        scSplit($notBackfilled, '2026-10-12 09:00:00');
        $gone = scBackfilled('2222', status: 'unavailable');
        scSplit($gone, '2026-10-12 09:00:00');
        $client = scTrackClient();

        // Act
        scRun();

        // Assert
        expect($client->stockRequests())->toBe([]);
    });

    test('追跡中で、保有の更新が確定した最新週まで保存済みの銘柄でも、新しい分割があれば取り直す', function () {
        // Arrange: tracked (deadline 2026-11-09) and the refresh saved the week of 2026-10-05 after it was confirmed
        $holding = scBackfilled('1111', status: 'active');
        PriceTrackingTarget::where('holding_id', $holding->id)->update(['last_sell_week' => '2026-05-11', 'track_until_week' => '2026-11-09']);
        app(WeeklyPriceRecorder::class)->recordHolding($holding, [['date' => '2026-10-05', 'close' => 50.0, 'volume' => 1]]);
        scSplit($holding, '2026-10-12 09:00:00');
        $client = scTrackClient();

        // Act
        $summary = scRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:1111']);
        expect([$summary->refetchedForSplits, $summary->savedAlready])->toBe([1, 0]);
    });
});

/**
 * Binds the refresh fakes: one JP symbol with 60 rising weeks and a 2:1 split.
 */
function scBindRefreshFakes(string $code): void
{
    $weeks = [];
    for ($i = 59; $i >= 0; $i--) {
        $weeks[] = ['date' => Carbon::parse('2026-10-11')->subWeeks($i)->toDateString(), 'close' => 1000.0 + (59 - $i), 'volume' => 100000];
    }
    $index = array_map(fn (array $w) => ['date' => $w['date'], 'close' => 30000.0, 'volume' => 0], $weeks);

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(
        [$code => $weeks],
        splits: [$code => [['date' => '2026-09-30', 'numerator' => 2, 'denominator' => 1]]],
    ));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient(['nikkei225' => $index, 'sp500' => $index]));
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);
}

describe('UC-018 分割の整合: 確認だけの実行（レビューで追加）', function () {
    test('確認だけの実行（dry run）は、分割で取り直す予定の銘柄数も数え、取得はしない', function () {
        // Arrange
        $holding = scBackfilled('1111');
        scSplit($holding, '2026-10-12 09:00:00');
        $client = scTrackClient();

        // Act
        $summary = app(TrackPriceHistoryAction::class)->execute(dryRun: true);

        // Assert
        expect($summary->refetchedForSplits)->toBe(1);
        expect($client->requests)->toBe([]);
        $this->artisan('price:track', ['--dry-run' => true])
            ->expectsOutputToContain('分割で取り直す予定 1')
            ->assertExitCode(0);
    });
});
