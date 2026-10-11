<?php

namespace Tests\Feature;

use App\Actions\PriceTracking\TrackPriceHistoryAction;
use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\PriceTrackingTarget;
use App\Models\SignalOccurrence;
use App\Models\StockSplit;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Models\WeeklyPrice;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\PriceHistory;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| UC-018 毎週の価格の追跡 — Red phase (CHG-0033 Cycle 6d)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0024-trade-and-signal-price-tracking.md D1・D2・D4
|   - docs/adr/ADR-0027-trade-history-storage.md D5・D6・D7
|   - docs/architecture/data-model.md `price_tracking_targets` の状態の更新規則
|   - Cycle 6c (BackfillPriceHistoryAction): the same pacing / abort rules
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Actions\PriceTracking\TrackPriceHistoryAction::execute(
|       bool $dryRun = false, bool $includeUnavailable = false)
|       : App\Actions\PriceTracking\Support\PriceTrackingSummary
|     (readonly ints targets / savedAlready / ok / notFound / empty /
|     failed / completed / indicesOk / indicesFailed, bool aborted).
|   - Step 1, register: every holding with a 'sell' in trade_executions or a
|     row in signal_occurrences gets / extends its tracking deadline
|     (PriceTrackingTargetUpdater::register: later of last sale and last
|     signal week, + 26 weeks; a completed target whose deadline moved
|     beyond what is saved becomes active again).
|   - Step 2, indices: nikkei225, sp500, usdjpy are fetched first with
|     PriceBackfillClientInterface::fetchIndex and saved (recordIndex), so
|     the UC-014 / UC-018 boundary week of an index is never behind.
|   - Step 3, targets = status 'active' AND track_until_week not null (a
|     holding without a deadline is covered by the holdings / watchlist
|     refresh). 'completed' and 'unavailable' are not fetched;
|     $includeUnavailable adds the unavailable ones (a retry).
|   - Step 4, per target: if weekly_prices already holds the latest
|     CONFIRMED week of the holding (saved by the holdings / watchlist
|     refresh), no request is made and the state is brought up to date
|     from the saved rows (savedAlready). Otherwise the same fetch as the
|     backfill (10 years + splits: fetchStock) → recordHolding →
|     StockSplitRecorder → applyFetch(backfill: true, splitsFetched: true).
|     A 10-year fetch, not 104 weeks, so that a new split also corrects the
|     weeks before the 104-week window (Yahoo closes are split-adjusted
|     retroactively) — a deliberate deviation from ADR-0024 D2's "104週";
|     the ADR gets an addendum.
|   - A week is confirmed once the next Monday has started. A target is
|     completed only when a confirmed week >= track_until_week is saved.
|   - Pacing 1500 ms between requests, abort after 5 'failed' in a row (an
|     answer from Yahoo resets), warnings log holding_id / index_name and
|     status only: identical to the backfill.
|   - Artisan `price:track {--dry-run} {--include-unavailable}`: prints
|     「対象 N」「成功 N」…, exit code 0, or 1 when aborted.
|   - Run on app start, not on a clock (approved 2026-10-10: the app is
|     used irregularly, mostly on weekends, and no scheduler runs):
|     scripts/start-app.bat starts `price:track` detached once the app
|     answers. Rerunning is cheap: saved holdings are not requested.
|
| Expected Red: the action, the summary, the command and the schedule do
| not exist yet.
|
*/

/**
 * Scripted stand-in for Yahoo: answers by symbol, records every request.
 */
class FakeTrackClient implements PriceBackfillClientInterface
{
    /** @var list<string> */
    public array $requests = [];

    /**
     * @param  array<string, PriceHistory>  $answers  keyed by "jp:7203" / "index:sp500"
     */
    public function __construct(private array $answers = [], private ?PriceHistory $fallback = null) {}

    public function fetchStock(string $market, string $symbolCode): PriceHistory
    {
        return $this->answer("{$market}:{$symbolCode}");
    }

    public function fetchIndex(string $indexName): PriceHistory
    {
        return $this->answer("index:{$indexName}");
    }

    private function answer(string $key): PriceHistory
    {
        $this->requests[] = $key;

        return $this->answers[$key] ?? $this->fallback ?? tkOk();
    }

    /**
     * @return list<string>
     */
    public function stockRequests(): array
    {
        return array_values(array_filter($this->requests, fn (string $r) => ! str_starts_with($r, 'index:')));
    }
}

/**
 * @param  list<string>  $dates
 * @param  list<array{date: string, numerator: int, denominator: int}>  $splits
 */
function tkOk(array $dates = ['2016-10-03', '2026-09-21', '2026-09-28'], array $splits = [], float $close = 100.0): PriceHistory
{
    return new PriceHistory(
        PriceHistory::OK,
        array_map(fn (string $d) => ['date' => $d, 'close' => $close, 'volume' => 1000], $dates),
        $splits,
    );
}

function tkFailed(): PriceHistory
{
    return new PriceHistory(PriceHistory::FAILED, message: 'HTTP 500');
}

function tkNotFound(): PriceHistory
{
    return new PriceHistory(PriceHistory::NOT_FOUND, message: 'Not Found');
}

function tkClient(FakeTrackClient $client): FakeTrackClient
{
    app()->instance(PriceBackfillClientInterface::class, $client);

    return $client;
}

function tkHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function tkTrade(Holding $holding, string $kind, string $date): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => 'specific', 'kind' => $kind, 'quantity' => 100, 'unit_price' => 1000,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy',
        'content_hash' => str_pad((string) (9000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * A holding sold on $sellDate (its tracking deadline = that week + 26 weeks).
 */
function tkSold(string $code, string $sellDate = '2026-05-13'): Holding
{
    $holding = tkHolding($code);
    tkTrade($holding, 'sell', $sellDate);

    return $holding;
}

function tkRun(bool $dryRun = false, bool $includeUnavailable = false)
{
    return app(TrackPriceHistoryAction::class)->execute($dryRun, $includeUnavailable);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00'); // Wednesday: the week of 2026-09-28 is the last confirmed one
    Sleep::fake();
});

describe('UC-018 毎週の追跡: 対象と期限', function () {
    test('売却済みの銘柄は期限つきで登録され、取得・保存され、確定した最新週まで保存済みと記録される', function () {
        // Arrange
        $sold = tkSold('1111'); // last sale week 2026-05-11 → deadline 2026-11-09
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:1111']);
        expect(WeeklyPrice::where('holding_id', $sold->id)->count())->toBe(3);
        $target = PriceTrackingTarget::where('holding_id', $sold->id)->sole();
        expect($target->track_until_week->toDateString())->toBe('2026-11-09');
        expect($target->latest_saved_week->toDateString())->toBe('2026-09-28');
        expect($target->status)->toBe('active');
        expect([$summary->targets, $summary->ok, $summary->aborted])->toBe([1, 1, false]);
    });

    test('直近のシグナル発生だけの銘柄（売買なし）も、発生の週から26週後を期限として追跡される', function () {
        // Arrange
        $signalOnly = tkHolding('2222');
        SignalOccurrence::create(['holding_id' => $signalOnly->id, 'source' => 'watchlist_buy', 'signal_type' => 'x', 'observed_week' => '2026-09-14', 'metrics' => []]);
        $client = tkClient(new FakeTrackClient);

        // Act
        tkRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:2222']);
        expect(PriceTrackingTarget::where('holding_id', $signalOnly->id)->sole()->track_until_week->toDateString())->toBe('2027-03-15');
    });

    test('新しい売却で期限が延びた完了済みの銘柄は、追跡中に戻って取得される', function () {
        // Arrange: it had completed (deadline 2026-08-31 reached), then it is sold again
        $holding = tkSold('3333', '2026-03-04');
        PriceTrackingTarget::create([
            'holding_id' => $holding->id, 'status' => 'completed', 'last_sell_week' => '2026-03-02',
            'track_until_week' => '2026-08-31', 'latest_saved_week' => '2026-09-28', 'backfilled_from_week' => '2016-10-03',
        ]);
        tkTrade($holding, 'sell', '2026-09-30'); // week of 2026-09-28 → new deadline 2027-03-29
        $client = tkClient(new FakeTrackClient(['jp:3333' => tkOk(['2026-09-28', '2026-10-05'])]));

        // Act
        tkRun();

        // Assert
        $target = PriceTrackingTarget::where('holding_id', $holding->id)->sole();
        expect($target->track_until_week->toDateString())->toBe('2027-03-29');
        expect($target->status)->toBe('active');
        expect($client->stockRequests())->toBe(['jp:3333']);
    });

    test('期限のない銘柄（保有中で補完だけの銘柄）・完了済み・取得不能の銘柄は取得しない', function () {
        // Arrange
        $held = tkHolding('1111');
        tkTrade($held, 'buy', '2022-07-04'); // never sold: no deadline
        $done = tkSold('2222', '2026-03-04');
        $gone = tkSold('3333');
        PriceTrackingTarget::create(['holding_id' => $done->id, 'status' => 'completed', 'last_sell_week' => '2026-03-02', 'track_until_week' => '2026-08-31', 'latest_saved_week' => '2026-09-28']);
        PriceTrackingTarget::create(['holding_id' => $gone->id, 'status' => 'unavailable', 'consecutive_failures' => 3, 'last_sell_week' => '2026-05-11', 'track_until_week' => '2026-11-09']);
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect($client->stockRequests())->toBe([]);
        expect($summary->targets)->toBe(0);
    });

    test('同じ銘柄に売却やシグナルが何件あっても、1回だけ取得する', function () {
        // Arrange
        $holding = tkSold('1111', '2026-04-01');
        tkTrade($holding, 'sell', '2026-05-13');
        foreach (['2026-04-06', '2026-05-18'] as $week) {
            SignalOccurrence::create(['holding_id' => $holding->id, 'source' => 'take_profit', 'signal_type' => 'x', 'observed_week' => $week, 'metrics' => []]);
        }
        $client = tkClient(new FakeTrackClient);

        // Act
        tkRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:1111']);
    });
});

describe('UC-018 毎週の追跡: 終点の確定と、既存の更新経路との共用', function () {
    test('終点の週が確定したあとに保存を確認できたら、完了にする', function () {
        // Arrange: sale week 2026-03-02 → deadline 2026-08-31, long confirmed
        $holding = tkSold('1111', '2026-03-04');
        tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect(PriceTrackingTarget::where('holding_id', $holding->id)->sole()->status)->toBe('completed');
        expect($summary->completed)->toBe(1);
    });

    test('終点の週がまだ確定していない間は完了にせず、確定した翌週の取得で完了にする', function () {
        // Arrange: deadline 2026-10-05 (the week that is still running on 2026-10-07)
        $holding = tkSold('1111', '2026-04-06');
        $client = tkClient(new FakeTrackClient(['jp:1111' => tkOk(['2026-09-28', '2026-10-05'])]));

        // Act
        tkRun();
        $afterFirst = PriceTrackingTarget::where('holding_id', $holding->id)->sole()->status;
        Carbon::setTestNow('2026-10-14 10:00:00'); // the week of 2026-10-05 is confirmed now
        tkRun();

        // Assert
        expect($afterFirst)->toBe('active');
        $target = PriceTrackingTarget::where('holding_id', $holding->id)->sole();
        expect([$target->status, $target->latest_saved_week->toDateString()])->toBe(['completed', '2026-10-05']);
        expect($client->stockRequests())->toBe(['jp:1111', 'jp:1111']);
    });

    test('保有・ウォッチリストの更新が確定した最新週まで保存済みの銘柄は、取得せず保存済みの週から状態を更新する', function () {
        // Arrange
        $holding = tkSold('1111');
        app(WeeklyPriceRecorder::class)->recordHolding($holding, [
            ['date' => '2026-09-21', 'close' => 100.0, 'volume' => 1], ['date' => '2026-09-28', 'close' => 101.0, 'volume' => 1],
        ]);
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect($client->stockRequests())->toBe([]);
        expect(PriceTrackingTarget::where('holding_id', $holding->id)->sole()->latest_saved_week->toDateString())->toBe('2026-09-28');
        expect([$summary->targets, $summary->savedAlready, $summary->ok])->toBe([1, 1, 0]);
    });

    test('新しい株式分割が出たら、10年分を取り直して分割より前の週も調整後の値になり、分割が記録される', function () {
        // Arrange: week 1 saves the old (unadjusted) prices
        $holding = tkSold('1111');
        tkClient(new FakeTrackClient(['jp:1111' => tkOk(['2016-10-03', '2026-09-28'], close: 100.0)]));
        tkRun();

        // Act: the next week Yahoo reports a 2:1 split and every close is halved retroactively
        Carbon::setTestNow('2026-10-14 10:00:00');
        tkClient(new FakeTrackClient(['jp:1111' => tkOk(['2016-10-03', '2026-09-28', '2026-10-05'], splits: [['date' => '2026-10-01', 'numerator' => 2, 'denominator' => 1]], close: 50.0)]));
        tkRun();

        // Assert
        expect((float) WeeklyPrice::where('holding_id', $holding->id)->where('week_date', '2016-10-03')->sole()->close)->toBe(50.0);
        expect(StockSplit::where('holding_id', $holding->id)->sole()->ratio_numerator)->toBe(2);
    });
});

describe('UC-018 毎週の追跡: 指数・ドル円', function () {
    test('日経225・S&P500・ドル円の週足が、銘柄より先に取得・保存される', function () {
        // Arrange
        tkSold('1111');
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect($client->requests)->toBe(['index:nikkei225', 'index:sp500', 'index:usdjpy', 'jp:1111']);
        expect(IndexWeeklyPrice::whereIn('index_name', ['nikkei225', 'sp500', 'usdjpy'])->count())->toBe(9);
        expect([$summary->indicesOk, $summary->indicesFailed])->toBe([3, 0]);
    });

    test('追跡する銘柄がなくても、指数・ドル円は更新する', function () {
        // Arrange
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect($client->requests)->toBe(['index:nikkei225', 'index:sp500', 'index:usdjpy']);
        expect($summary->indicesOk)->toBe(3);
    });
});

describe('UC-018 毎週の追跡: 失敗と取得不能', function () {
    test('取得失敗は連続失敗に数えて完了にせず、取得不能にはしない', function () {
        // Arrange
        $holding = tkSold('1111');
        tkClient(new FakeTrackClient(['jp:1111' => tkFailed()]));

        // Act
        $summary = tkRun();

        // Assert
        $target = PriceTrackingTarget::where('holding_id', $holding->id)->sole();
        expect([$target->status, $target->consecutive_failures])->toBe(['active', 1]);
        expect($summary->failed)->toBe(1);
    });

    test('銘柄なしが3回の追跡で続いたら取得不能になり、次回からは取得しない', function () {
        // Arrange
        $holding = tkSold('1111');
        $client = tkClient(new FakeTrackClient(['jp:1111' => tkNotFound()]));

        // Act
        tkRun();
        tkRun();
        $afterTwo = PriceTrackingTarget::where('holding_id', $holding->id)->sole()->status;
        tkRun();
        $afterThree = PriceTrackingTarget::where('holding_id', $holding->id)->sole()->status;
        tkRun();

        // Assert
        expect([$afterTwo, $afterThree])->toBe(['active', 'unavailable']);
        expect($client->stockRequests())->toBe(['jp:1111', 'jp:1111', 'jp:1111']);
    });

    test('取得不能の銘柄は、再取得の指定をすれば取得され、成功すれば追跡中に戻る', function () {
        // Arrange
        $holding = tkSold('1111');
        PriceTrackingTarget::create(['holding_id' => $holding->id, 'status' => 'unavailable', 'consecutive_failures' => 3, 'last_sell_week' => '2026-05-11', 'track_until_week' => '2026-11-09']);
        $client = tkClient(new FakeTrackClient);

        // Act
        tkRun(includeUnavailable: true);

        // Assert
        expect($client->stockRequests())->toBe(['jp:1111']);
        $target = PriceTrackingTarget::where('holding_id', $holding->id)->sole();
        expect([$target->status, $target->consecutive_failures])->toBe(['active', 0]);
    });

    test('取得と取得の間に1.5秒の間隔を空け、取得失敗が5回続いたら残りを取得せず中断する', function () {
        // Arrange: every request fails (the 3 indices and 2 holdings), the 3rd holding is never requested
        foreach (['1111', '2222', '3333'] as $code) {
            tkSold($code);
        }
        $client = tkClient(new FakeTrackClient(fallback: tkFailed()));

        // Act
        $summary = tkRun();

        // Assert
        expect($client->requests)->toHaveCount(5);
        expect([$summary->aborted, $summary->failed, $summary->indicesFailed])->toBe([true, 2, 3]);
        Sleep::assertSleptTimes(4);
        Sleep::assertSequence(array_fill(0, 4, Sleep::for(1500)->milliseconds()));
        expect(PriceTrackingTarget::where('status', 'unavailable')->count())->toBe(0);
    });
});

describe('UC-018 毎週の追跡: 確認だけの実行・コマンド・定期実行', function () {
    test('確認だけの実行（dry run）は、対象数を数えるだけで、期限の登録も取得も保存もしない', function () {
        // Arrange
        tkSold('1111');
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun(dryRun: true);

        // Assert
        expect($client->requests)->toBe([]);
        expect($summary->targets)->toBe(1);
        expect(PriceTrackingTarget::count())->toBe(0);
        expect(WeeklyPrice::count() + IndexWeeklyPrice::count())->toBe(0);
    });

    test('price:track コマンドは件数を表示し、成功なら終了コード0で終わる', function () {
        // Arrange
        tkSold('1111');
        tkClient(new FakeTrackClient);

        // Act / Assert
        $this->artisan('price:track')
            ->expectsOutputToContain('対象 1')
            ->expectsOutputToContain('成功 1')
            ->assertExitCode(0);
    });

    test('price:track コマンドは、中断したら中断した旨を表示し終了コード1で終わる', function () {
        // Arrange
        foreach (['1111', '2222'] as $code) {
            tkSold($code);
        }
        tkClient(new FakeTrackClient(fallback: tkFailed()));

        // Act / Assert
        $this->artisan('price:track')
            ->expectsOutputToContain('中断')
            ->assertExitCode(1);
    });

    test('起動スクリプトは、アプリの応答を確認したあとに price:track を後ろで（起動を待たせずに）実行する', function () {
        // Arrange: the app is used irregularly (mostly on weekends), so the run is tied to app start, not a clock
        $script = file_get_contents(base_path('scripts/start-app.bat'));

        // Act
        $appOk = strpos($script, ':app_ok');
        $open = strpos($script, ':open');
        $track = strpos($script, 'docker compose exec -d laravel.test php artisan price:track');

        // Assert: detached (-d), and only on the path where the app answered
        expect($track)->not->toBeFalse();
        expect($track)->toBeGreaterThan($appOk);
        expect($track)->toBeLessThan($open);
    });
});

describe('UC-018 毎週の追跡: 二重起動の防止と、何度起動しても軽く済むこと', function () {
    /*
     * Contract (added 2026-10-10 at the user's request, Gate 4):
     *   - price:track and price:backfill share one lock, Cache::lock('price-fetch').
     *     When it is held, the command requests nothing, prints 「実行中」 and
     *     exits 0 (an app start must not fail because a run is still going).
     *     The lock is released when the run ends.
     *   - Indices: an index whose last confirmed week was saved after that week
     *     was confirmed is not requested again (the same rule as holdings), so a
     *     second start on the same day makes no request at all.
     */
    test('price:track は、価格の取得（price:track・price:backfill）がすでに実行中なら、何も取得せずに終了する', function () {
        // Arrange
        tkSold('1111');
        $client = tkClient(new FakeTrackClient);
        $lock = Cache::lock('price-fetch', 600);
        expect($lock->get())->toBeTrue();

        // Act / Assert
        try {
            $this->artisan('price:track')
                ->expectsOutputToContain('実行中')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }
        expect($client->requests)->toBe([]);
    });

    test('price:backfill も、価格の取得がすでに実行中なら、何も取得せずに終了する', function () {
        // Arrange
        tkTrade(tkHolding('1111'), 'buy', '2022-07-04');
        $client = tkClient(new FakeTrackClient);
        $lock = Cache::lock('price-fetch', 600);
        expect($lock->get())->toBeTrue();

        // Act / Assert
        try {
            $this->artisan('price:backfill')
                ->expectsOutputToContain('実行中')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }
        expect($client->requests)->toBe([]);
    });

    test('実行が終われば（中断した場合も）ロックは解放され、次の起動で実行できる', function () {
        // Arrange: 3 indices + 2 holdings failing = 5 failures in a row → aborted
        tkSold('1111');
        tkSold('2222');
        tkClient(new FakeTrackClient(fallback: tkFailed()));

        // Act: an aborted run, then a successful one
        $this->artisan('price:track')->assertExitCode(1);
        tkClient(new FakeTrackClient);
        $this->artisan('price:track')->assertExitCode(0);

        // Assert
        expect(Cache::lock('price-fetch', 600)->get())->toBeTrue();
    });

    test('同じ日の2回目の起動では、確定後に保存済みの指数・銘柄を取得し直さない（取得は0件）', function () {
        // Arrange: the first start fetches everything
        tkSold('1111');
        tkClient(new FakeTrackClient);
        tkRun();

        // Act: a second start, the same day
        $client = tkClient(new FakeTrackClient);
        $summary = tkRun();

        // Assert
        expect($client->requests)->toBe([]);
        expect([$summary->savedAlready, $summary->indicesOk])->toBe([1, 0]);
    });

    test('指数の確定した最新週が、週の途中に保存されたままなら、取得し直す', function () {
        // Arrange: on Friday 2026-10-02 the index row of the running week 2026-09-28 was written
        Carbon::setTestNow('2026-10-02 10:00:00');
        foreach (['nikkei225', 'sp500', 'usdjpy'] as $name) {
            app(WeeklyPriceRecorder::class)->recordIndex($name, [['date' => '2026-09-28', 'close' => 1.0, 'volume' => 0]]);
        }
        Carbon::setTestNow('2026-10-07 10:00:00'); // the week of 2026-09-28 is confirmed now
        $client = tkClient(new FakeTrackClient);

        // Act
        tkRun();

        // Assert
        expect($client->requests)->toBe(['index:nikkei225', 'index:sp500', 'index:usdjpy']);
    });
});

describe('UC-018 毎週の追跡: 境界と異常時（レビューで追加）', function () {
    test('シグナルは、終点の週がまだ保存を待っている間は追跡し、終点が確定済みの古いシグナルは追跡しない', function () {
        // Arrange (2026-10-07; the last confirmed week is 2026-09-28)
        $pending = tkHolding('1111'); // 2026-04-06 + 26 weeks = 2026-10-05: not confirmed yet
        $old = tkHolding('2222');     // 2026-03-23 + 26 weeks = 2026-09-21: confirmed long ago
        SignalOccurrence::create(['holding_id' => $pending->id, 'source' => 'buy', 'signal_type' => 'x', 'observed_week' => '2026-04-06', 'metrics' => []]);
        SignalOccurrence::create(['holding_id' => $old->id, 'source' => 'buy', 'signal_type' => 'x', 'observed_week' => '2026-03-23', 'metrics' => []]);
        $client = tkClient(new FakeTrackClient);

        // Act
        tkRun();

        // Assert
        expect($client->stockRequests())->toBe(['jp:1111']);
        expect(PriceTrackingTarget::where('holding_id', $pending->id)->sole()->status)->toBe('active');
        expect(PriceTrackingTarget::where('holding_id', $old->id)->exists())->toBeFalse();
    });

    test('週は翌週の月曜0時（UTC）に確定し、日曜の最後の瞬間にはまだ確定していない', function (string $now, string $expected) {
        // Arrange: deadline 2026-10-05
        Carbon::setTestNow($now);
        $holding = tkSold('1111', '2026-04-06');
        tkClient(new FakeTrackClient(['jp:1111' => tkOk(['2026-09-28', '2026-10-05'])]));

        // Act
        tkRun();

        // Assert
        expect(PriceTrackingTarget::where('holding_id', $holding->id)->sole()->status)->toBe($expected);
    })->with([
        '日曜 23:59:59' => ['2026-10-11 23:59:59', 'active'],
        '月曜 00:00:00' => ['2026-10-12 00:00:00', 'completed'],
    ]);

    test('取得せずに保存済みの行で状態を更新したときも、期限に届いていれば完了にする', function () {
        // Arrange: deadline 2026-08-31; the refresh saved the week of 2026-09-28 after it was confirmed
        $holding = tkSold('1111', '2026-03-04');
        app(WeeklyPriceRecorder::class)->recordHolding($holding, [['date' => '2026-09-28', 'close' => 100.0, 'volume' => 1]]);
        $client = tkClient(new FakeTrackClient);

        // Act
        $summary = tkRun();

        // Assert
        expect($client->stockRequests())->toBe([]);
        expect(PriceTrackingTarget::where('holding_id', $holding->id)->sole()->status)->toBe('completed');
        expect([$summary->savedAlready, $summary->completed])->toBe([1, 1]);
    });

    test('処理が例外で落ちても、ロックは解放され、次の起動で実行できる', function () {
        // Arrange: the price client blows up mid-run
        tkSold('1111');
        app()->instance(PriceBackfillClientInterface::class, new class extends FakeTrackClient
        {
            public function fetchIndex(string $indexName): PriceHistory
            {
                throw new \RuntimeException('simulated crash');
            }
        });

        // Act
        try {
            Artisan::call('price:track');
        } catch (\RuntimeException) {
            // expected
        }

        // Assert
        expect(Cache::lock('price-fetch', 600)->get())->toBeTrue();
    });
});
