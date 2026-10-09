<?php

namespace Tests\Feature;

use App\Actions\PriceTracking\BackfillPriceHistoryAction;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| UC-018 初回の価格の一括補完 — Red phase (CHG-0033 Cycle 6c)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0024-trade-and-signal-price-tracking.md D1・D5
|   - docs/adr/ADR-0027-trade-history-storage.md D5・D6・D7
|   - docs/architecture/data-model.md `price_tracking_targets`
|   - Observed Yahoo behaviour (2026-10-05〜09): a bare 404 for every
|     symbol for ~15 minutes, otherwise 0.25〜0.65 s per request.
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Actions\PriceTracking\BackfillPriceHistoryAction::execute(
|       bool $dryRun = false): PriceBackfillSummary
|     (App\Actions\PriceTracking\Support\PriceBackfillSummary, readonly
|     ints targets / skipped / ok / notFound / empty / failed /
|     indicesOk / indicesFailed, bool aborted).
|   - Targets: every holding that appears in trade_executions (any kind,
|     any review_status), once each, by holding id; plus the indices
|     nikkei225, sp500, usdjpy (fetched first, by
|     PriceBackfillClientInterface::fetchIndex, saved with
|     WeeklyPriceRecorder::recordIndex; no state row).
|   - Every target holding is registered first with
|     PriceTrackingTargetUpdater::register(holding, last 'sell' trade_date,
|     last signal_occurrences.observed_week).
|   - A holding whose target already has backfilled_from_week, or whose
|     status is 'unavailable', is skipped (not fetched). A rerun therefore
|     only fetches what an earlier run did not finish.
|   - Per fetched holding: fetchStock(market, symbol_code) →
|     WeeklyPriceRecorder::recordHolding → StockSplitRecorder::record →
|     PriceTrackingTargetUpdater::applyFetch(backfill: true).
|   - Pacing: Sleep 1500 ms between two requests (not before the first).
|   - Abort: 5 'failed' results in a row (indices included) stop the run;
|     the rest is not requested and the summary says aborted. A not_found /
|     empty / ok answer resets the run of failures (Yahoo did answer).
|   - Every non-ok result is logged as a warning with holding_id (or
|     index_name) and status only — no prices.
|   - dryRun: counts targets / skipped, requests nothing, writes nothing.
|   - Artisan `price:backfill {--dry-run}` prints the summary; exit code
|     0, or 1 when aborted.
|
| Expected Red: the action, the summary and the command do not exist yet.
|
*/

/**
 * Scripted stand-in for Yahoo: answers by symbol, records every request.
 */
class FakeBackfillClient implements PriceBackfillClientInterface
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

        return $this->answers[$key] ?? $this->fallback ?? bfOk();
    }
}

/**
 * @param  list<array{date: string, numerator: int, denominator: int}>  $splits
 */
function bfOk(array $dates = ['2016-10-03', '2016-10-10', '2026-09-28'], array $splits = [], bool $splitsIncomplete = false): PriceHistory
{
    return new PriceHistory(
        PriceHistory::OK,
        array_map(fn (string $d) => ['date' => $d, 'close' => 100.0, 'volume' => 1000], $dates),
        $splits,
        $splitsIncomplete,
    );
}

function bfFailed(): PriceHistory
{
    return new PriceHistory(PriceHistory::FAILED, message: 'HTTP 404');
}

function bfClient(FakeBackfillClient $client): FakeBackfillClient
{
    app()->instance(PriceBackfillClientInterface::class, $client);

    return $client;
}

function bfHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function bfTrade(Holding $holding, string $kind, string $date): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => 'specific', 'kind' => $kind, 'quantity' => 100, 'unit_price' => 1000,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy',
        'content_hash' => str_pad((string) $seq, 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

function bfRun(bool $dryRun = false)
{
    return app(BackfillPriceHistoryAction::class)->execute($dryRun);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    Sleep::fake();
});

describe('UC-018 初回の一括補完: 対象と保存', function () {
    test('売買履歴に現れた銘柄と、日経225・S&P500・ドル円の週足が保存され、銘柄は補完済みとして記録される', function () {
        // Arrange
        $jp = bfHolding('7203');
        $us = bfHolding('NVDA', 'us');
        bfTrade($jp, 'buy', '2022-07-04');
        bfTrade($us, 'buy', '2024-08-05');
        $client = bfClient(new FakeBackfillClient);

        // Act
        $summary = bfRun();

        // Assert
        expect($client->requests)->toBe(['index:nikkei225', 'index:sp500', 'index:usdjpy', 'jp:7203', 'us:NVDA']);
        expect(WeeklyPrice::where('holding_id', $jp->id)->count())->toBe(3);
        expect(WeeklyPrice::where('holding_id', $us->id)->count())->toBe(3);
        expect(IndexWeeklyPrice::where('index_name', 'usdjpy')->count())->toBe(3);
        expect(PriceTrackingTarget::where('holding_id', $jp->id)->sole()->backfilled_from_week->toDateString())->toBe('2016-10-03');
        expect([$summary->targets, $summary->ok, $summary->indicesOk, $summary->aborted])->toBe([2, 2, 3, false]);
    });

    test('同じ銘柄の売買が何件あっても1回だけ取得し、売買履歴にない保有銘柄は対象にしない', function () {
        // Arrange
        $traded = bfHolding('7203');
        bfTrade($traded, 'buy', '2022-07-04');
        bfTrade($traded, 'sell', '2023-01-10');
        bfTrade($traded, 'buy', '2024-02-01');
        bfHolding('9984'); // held but never traded in the history
        $client = bfClient(new FakeBackfillClient);

        // Act
        $summary = bfRun();

        // Assert
        expect(array_values(array_filter($client->requests, fn ($r) => ! str_starts_with($r, 'index:'))))->toBe(['jp:7203']);
        expect($summary->targets)->toBe(1);
        expect(PriceTrackingTarget::count())->toBe(1);
    });

    test('取得した株式分割が保存され、分割情報の欠損も記録される', function () {
        // Arrange
        $a = bfHolding('1111');
        $b = bfHolding('2222');
        bfTrade($a, 'buy', '2022-07-04');
        bfTrade($b, 'buy', '2022-07-04');
        bfClient(new FakeBackfillClient([
            'jp:1111' => bfOk(splits: [['date' => '2024-04-01', 'numerator' => 5, 'denominator' => 1]]),
            'jp:2222' => bfOk(splitsIncomplete: true),
        ]));

        // Act
        bfRun();

        // Assert
        expect(StockSplit::where('holding_id', $a->id)->sole()->ratio_numerator)->toBe(5);
        expect(PriceTrackingTarget::where('holding_id', $b->id)->sole()->splits_incomplete)->toBeTrue();
    });

    test('追跡期限は、最後の売却と最後のシグナル発生のうち遅い方から26週後で登録される', function () {
        // Arrange
        $sold = bfHolding('1111');
        bfTrade($sold, 'buy', '2025-01-06');
        bfTrade($sold, 'sell', '2026-03-04'); // week of 2026-03-02
        bfTrade($sold, 'sell', '2026-05-13'); // week of 2026-05-11 (the last sale)
        SignalOccurrence::create(['holding_id' => $sold->id, 'source' => 'take_profit', 'signal_type' => 'x', 'observed_week' => '2026-04-06', 'metrics' => []]);
        $held = bfHolding('2222');
        bfTrade($held, 'buy', '2025-01-06');
        bfClient(new FakeBackfillClient);

        // Act
        bfRun();

        // Assert
        expect(PriceTrackingTarget::where('holding_id', $sold->id)->sole()->track_until_week->toDateString())->toBe('2026-11-09');
        expect(PriceTrackingTarget::where('holding_id', $held->id)->sole()->track_until_week)->toBeNull();
    });
});

describe('UC-018 初回の一括補完: 再実行と取得できない銘柄', function () {
    test('再実行では、補完済みと取得不能の銘柄は取得せず、前回終わらなかった銘柄だけを取得する', function () {
        // Arrange
        $done = bfHolding('1111');
        $gone = bfHolding('2222');
        $todo = bfHolding('3333');
        foreach ([$done, $gone, $todo] as $h) {
            bfTrade($h, 'buy', '2022-07-04');
        }
        PriceTrackingTarget::create(['holding_id' => $done->id, 'backfilled_from_week' => '2016-10-03']);
        PriceTrackingTarget::create(['holding_id' => $gone->id, 'status' => 'unavailable', 'consecutive_failures' => 3]);
        $client = bfClient(new FakeBackfillClient);

        // Act
        $summary = bfRun();

        // Assert
        expect(array_values(array_filter($client->requests, fn ($r) => ! str_starts_with($r, 'index:'))))->toBe(['jp:3333']);
        expect([$summary->targets, $summary->skipped, $summary->ok])->toBe([3, 2, 1]);
    });

    test('銘柄なし・データなしは記録して次の銘柄へ進み、全体は中断しない', function () {
        // Arrange
        $missing = bfHolding('1111');
        $blank = bfHolding('2222');
        $fine = bfHolding('3333');
        foreach ([$missing, $blank, $fine] as $h) {
            bfTrade($h, 'buy', '2022-07-04');
        }
        bfClient(new FakeBackfillClient([
            'jp:1111' => new PriceHistory(PriceHistory::NOT_FOUND, message: 'Not Found'),
            'jp:2222' => new PriceHistory(PriceHistory::EMPTY),
        ]));

        // Act
        $summary = bfRun();

        // Assert
        expect([$summary->notFound, $summary->empty, $summary->ok, $summary->aborted])->toBe([1, 1, 1, false]);
        $target = PriceTrackingTarget::where('holding_id', $missing->id)->sole();
        expect([$target->consecutive_failures, $target->backfilled_from_week])->toBe([1, null]);
        expect(WeeklyPrice::where('holding_id', $fine->id)->count())->toBe(3);
    });
});

describe('UC-018 初回の一括補完: 取得元の障害（間隔・中断・ログ）', function () {
    test('取得と取得の間に1.5秒の間隔を空ける（最初の取得の前は待たない）', function () {
        // Arrange
        foreach (['1111', '2222'] as $code) {
            bfTrade(bfHolding($code), 'buy', '2022-07-04');
        }
        bfClient(new FakeBackfillClient);

        // Act
        bfRun();

        // Assert: 3 indices + 2 holdings = 5 requests → 4 pauses
        Sleep::assertSleptTimes(4);
        Sleep::assertSequence(array_fill(0, 4, Sleep::for(1500)->milliseconds()));
    });

    test('取得失敗が5回続いたら、残りを取得せずに中断し、中断したことが結果に残る', function () {
        // Arrange: every request fails, like the bare-404 outage of 2026-10-05
        $holdings = [];
        foreach (['1111', '2222', '3333', '4444'] as $code) {
            $holdings[] = $h = bfHolding($code);
            bfTrade($h, 'buy', '2022-07-04');
        }
        $client = bfClient(new FakeBackfillClient(fallback: bfFailed()));

        // Act
        $summary = bfRun();

        // Assert: 3 indices + 2 holdings failed, holdings 3333 / 4444 were never requested
        expect($client->requests)->toHaveCount(5);
        expect([$summary->aborted, $summary->failed, $summary->indicesFailed])->toBe([true, 2, 3]);
        expect(PriceTrackingTarget::where('holding_id', $holdings[2]->id)->sole()->last_attempted_at)->toBeNull();
        expect(PriceTrackingTarget::where('status', 'unavailable')->count())->toBe(0);
    });

    test('失敗の間に取得元の応答（成功・銘柄なし）を挟めば、失敗が続いたとはみなさず中断しない', function () {
        // Arrange
        $codes = ['1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888'];
        foreach ($codes as $code) {
            bfTrade(bfHolding($code), 'buy', '2022-07-04');
        }
        bfClient(new FakeBackfillClient([
            'jp:1111' => bfFailed(), 'jp:2222' => bfFailed(), 'jp:3333' => bfFailed(), 'jp:4444' => bfFailed(),
            'jp:5555' => new PriceHistory(PriceHistory::NOT_FOUND, message: 'Not Found'),
            'jp:6666' => bfFailed(), 'jp:7777' => bfFailed(), 'jp:8888' => bfFailed(),
        ]));

        // Act
        $summary = bfRun();

        // Assert
        expect([$summary->aborted, $summary->failed, $summary->notFound])->toBe([false, 7, 1]);
    });

    test('取得できなかった銘柄・指数は、銘柄IDまたは指数名と結果だけを警告ログに残す（価格は出さない）', function () {
        // Arrange
        Log::spy();
        $h = bfHolding('1111');
        bfTrade($h, 'buy', '2022-07-04');
        bfClient(new FakeBackfillClient([
            'index:usdjpy' => bfFailed(),
            'jp:1111' => new PriceHistory(PriceHistory::NOT_FOUND, message: 'Not Found'),
        ]));

        // Act
        bfRun();

        // Assert
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c = []) => ($c['holding_id'] ?? null) === $h->id && ($c['status'] ?? null) === 'not_found'
                && array_diff(array_keys($c), ['holding_id', 'status', 'message']) === [])
            ->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c = []) => ($c['index_name'] ?? null) === 'usdjpy' && ($c['status'] ?? null) === 'failed')
            ->once();
    });
});

describe('UC-018 初回の一括補完: 確認だけの実行とコマンド', function () {
    test('確認だけの実行（dry run）は、対象数を数えるだけで取得も保存もしない', function () {
        // Arrange
        $done = bfHolding('1111');
        bfTrade($done, 'buy', '2022-07-04');
        PriceTrackingTarget::create(['holding_id' => $done->id, 'backfilled_from_week' => '2016-10-03']);
        bfTrade(bfHolding('2222'), 'buy', '2022-07-04');
        $client = bfClient(new FakeBackfillClient);

        // Act
        $summary = bfRun(dryRun: true);

        // Assert
        expect($client->requests)->toBe([]);
        expect([$summary->targets, $summary->skipped])->toBe([2, 1]);
        expect(PriceTrackingTarget::count())->toBe(1);
        expect(WeeklyPrice::count() + IndexWeeklyPrice::count())->toBe(0);
    });

    test('price:backfill コマンドは結果の件数を表示し、成功なら終了コード0で終わる', function () {
        // Arrange
        bfTrade(bfHolding('1111'), 'buy', '2022-07-04');
        bfClient(new FakeBackfillClient);

        // Act / Assert
        $this->artisan('price:backfill')
            ->expectsOutputToContain('対象 1')
            ->expectsOutputToContain('成功 1')
            ->assertExitCode(0);
    });

    test('price:backfill コマンドは、中断したら中断した旨を表示し終了コード1で終わる', function () {
        // Arrange
        foreach (['1111', '2222', '3333'] as $code) {
            bfTrade(bfHolding($code), 'buy', '2022-07-04');
        }
        bfClient(new FakeBackfillClient(fallback: bfFailed()));

        // Act / Assert
        $this->artisan('price:backfill')
            ->expectsOutputToContain('中断')
            ->assertExitCode(1);
    });

    test('price:backfill --dry-run は取得せずに対象数だけを表示する', function () {
        // Arrange
        bfTrade(bfHolding('1111'), 'buy', '2022-07-04');
        $client = bfClient(new FakeBackfillClient);

        // Act / Assert
        $this->artisan('price:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('対象 1')
            ->assertExitCode(0);
        expect($client->requests)->toBe([]);
    });
});
