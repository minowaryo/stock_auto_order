<?php

namespace Tests\Feature;

use App\Jobs\RefreshWatchlistMarketDataJob;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\WatchlistItem;
use App\Models\WatchlistRefreshRun;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| UC-019 / 起動時のウォッチリスト指標・シグナル記録の自動更新 — Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/product/use-cases.md UC-019 (Gate 2, 2026-10-19) の
| 「起動時にウォッチリストの指標・シグナル記録を3日間隔で自動実行する」。
|
| 「実行しない」ことを確かめるテストは、実装がなくても素通りで成功してしまう
| （空虚）ため、どれも「実行すべき条件に変えると積まれる」対照を同じテスト内に
| 持たせている。実装前はその対照側で失敗する。
*/

/** Replace only the child startup commands; app:on-start itself is the real one. */
function uc019RegisterChild(string $signature, Closure $callback): void
{
    $command = new class($signature, $callback) extends Command
    {
        public function __construct(string $signature, private readonly Closure $callback)
        {
            $this->signature = $signature;
            parent::__construct();
        }

        public function handle(): int
        {
            return ($this->callback)($this);
        }
    };
    app(Kernel::class)->registerCommand($command);
}

/** @param array<int, string> $events */
function uc019StubChildren(array &$events = []): void
{
    Http::preventStrayRequests();
    uc019RegisterChild('price:track', function () use (&$events) {
        $events[] = 'price';

        return 0;
    });
    uc019RegisterChild('research:check-sources {--force}', function () use (&$events) {
        $events[] = 'research';

        return 0;
    });
}

function uc019UnheldWatchlistItem(string $code = '1111'): WatchlistItem
{
    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => "銘柄{$code}",
        'first_detected_at' => now(),
    ]);

    return WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => 'テーマA',
        'exchange_label' => '東Ｐ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => false,
        'last_close' => 1500.0,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);
}

function uc019HoldOnLatestSnapshot(Holding $holding): void
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
        'mutual_fund_filename' => null, 'imported_count' => 1, 'error_count' => 0, 'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id,
        'quantity' => 10, 'average_cost' => 1000, 'current_price' => 1200, 'fx_rate_used' => null,
        'unrealized_gain_amount' => 2000, 'unrealized_gain_rate' => 20.0, 'is_newly_detected' => false,
    ]);
}

function uc019Run(string $status, ?Carbon $finishedAt): WatchlistRefreshRun
{
    return WatchlistRefreshRun::create(['status' => $status, 'finished_at' => $finishedAt]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
    Queue::fake();
});

test('UC-019 起動時、成功した更新の記録がなければウォッチリスト更新を積み、queuedの実行記録を作って渡す', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();

    Artisan::call('app:on-start');

    expect(WatchlistRefreshRun::query()->count())->toBe(1);
    $run = WatchlistRefreshRun::query()->first();
    expect($run->status)->toBe(WatchlistRefreshRun::STATUS_QUEUED);
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, fn ($job) => $job->runId === $run->id);
});

test('UC-019 起動時のウォッチリスト更新は価格追跡の後・情報源確認の前に積まれる', function () {
    Http::preventStrayRequests();
    uc019UnheldWatchlistItem();
    $observed = [];
    uc019RegisterChild('price:track', function () use (&$observed) {
        $observed['queued_before_price'] = Queue::pushed(RefreshWatchlistMarketDataJob::class)->count();

        return 0;
    });
    uc019RegisterChild('research:check-sources {--force}', function () use (&$observed) {
        $observed['queued_before_research'] = Queue::pushed(RefreshWatchlistMarketDataJob::class)->count();

        return 0;
    });

    Artisan::call('app:on-start');

    expect($observed)->toBe(['queued_before_price' => 0, 'queued_before_research' => 1]);
});

test('UC-019 前回の成功から72時間未満なら積まず、ちょうど72時間で積む', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subHours(72)->addSecond());

    Artisan::call('app:on-start');
    Queue::assertNothingPushed();

    // 対照: 境界（ちょうど72時間）
    WatchlistRefreshRun::query()->delete();
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subHours(72));

    Artisan::call('app:on-start');
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 前回の成功から72時間を超えていれば積む', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subHours(100));

    Artisan::call('app:on-start');

    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 間隔は最新の成功記録で判定し、古い成功記録があっても新しい成功が72時間未満なら積まない', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subDays(10));
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subHours(10));

    Artisan::call('app:on-start');
    Queue::assertNothingPushed();

    // 対照: 新しい成功が古くなれば積む
    $this->travelTo(now()->addHours(70));
    Artisan::call('app:on-start');
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 失敗した実行記録は間隔の起点にせず、直近に失敗していても積む', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    uc019Run(WatchlistRefreshRun::STATUS_FAILED, now()->subHour());
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subDays(5));

    Artisan::call('app:on-start');

    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 queued/processingの実行記録が既にあれば二重に積まず、解消後は積む', function (string $activeStatus) {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    $active = uc019Run($activeStatus, null);

    Artisan::call('app:on-start');
    Queue::assertNothingPushed();
    expect(WatchlistRefreshRun::query()->count())->toBe(1);

    // 対照: 実行中でなくなれば（成功記録もないので）積む
    $active->update(['status' => WatchlistRefreshRun::STATUS_FAILED, 'finished_at' => now()]);
    Artisan::call('app:on-start');
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
})->with([
    'queued' => WatchlistRefreshRun::STATUS_QUEUED,
    'processing' => WatchlistRefreshRun::STATUS_PROCESSING,
]);

test('UC-019 ウォッチリストが空なら積まず、実行記録も作らない。銘柄が入れば積む', function () {
    $events = [];
    uc019StubChildren($events);

    Artisan::call('app:on-start');
    Queue::assertNothingPushed();
    expect(WatchlistRefreshRun::query()->count())->toBe(0);

    // 対照: 未保有の銘柄が入れば積む
    uc019UnheldWatchlistItem();
    Artisan::call('app:on-start');
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 ウォッチリストが保有銘柄だけなら（未保有0件）積まず、未保有が加われば積む', function () {
    $events = [];
    uc019StubChildren($events);
    $held = uc019UnheldWatchlistItem('3333');
    uc019HoldOnLatestSnapshot($held->holding);

    Artisan::call('app:on-start');
    Queue::assertNothingPushed();
    expect(WatchlistRefreshRun::query()->count())->toBe(0);

    uc019UnheldWatchlistItem('4444');
    Artisan::call('app:on-start');
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 ウォッチリスト更新を積んでも、価格追跡と情報源確認は従来どおりこの順で実行される', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();

    Artisan::call('app:on-start');

    expect($events)->toBe(['price', 'research']);
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 価格追跡が失敗してもウォッチリスト更新は積まれ、情報源確認も実行される', function () {
    Http::preventStrayRequests();
    $events = [];
    uc019UnheldWatchlistItem();
    uc019RegisterChild('price:track', function () use (&$events) {
        $events[] = 'price';
        throw new RuntimeException('価格取得失敗');
    });
    uc019RegisterChild('research:check-sources {--force}', function () use (&$events) {
        $events[] = 'research';

        return 0;
    });

    Artisan::call('app:on-start');

    expect($events)->toBe(['price', 'research']);
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});

test('UC-019 ウォッチリスト更新のジョブ投入で例外が出ても情報源確認は実行される', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    $throwing = new class(app()) extends QueueFake
    {
        public int $attempts = 0;

        public function push($job, $data = '', $queue = null)
        {
            $this->attempts++;

            throw new RuntimeException('キュー接続失敗');
        }
    };
    Queue::swap($throwing);

    Artisan::call('app:on-start');

    // 投入を試みたうえで、後続の情報源確認は止まらない
    expect($throwing->attempts)->toBe(1);
    expect($events)->toBe(['price', 'research']);
});

test('UC-019 ジョブ投入が例外になったら、作成済みのqueued実行記録はfailedに更新され、実行中として残らない', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    $throwing = new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null)
        {
            throw new RuntimeException('キュー接続失敗');
        }
    };
    Queue::swap($throwing);

    Artisan::call('app:on-start');

    $run = WatchlistRefreshRun::query()->sole();
    expect($run->status)->toBe(WatchlistRefreshRun::STATUS_FAILED);
    expect($run->finished_at)->not->toBeNull();
    expect(WatchlistRefreshRun::active()->count())->toBe(0);
    // 情報源確認は止まらない
    expect($events)->toBe(['price', 'research']);
});

test('UC-019 --forceを付けても72時間未満ならウォッチリスト更新は積まず、72時間以上なら積む', function () {
    $events = [];
    uc019StubChildren($events);
    uc019UnheldWatchlistItem();
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subHours(72)->addSecond());

    Artisan::call('app:on-start', ['--force' => true]);
    Queue::assertNothingPushed();
    expect(WatchlistRefreshRun::query()->count())->toBe(1);

    // 対照: --forceでも72時間以上経っていれば積む
    WatchlistRefreshRun::query()->delete();
    uc019Run(WatchlistRefreshRun::STATUS_COMPLETED, now()->subHours(72));

    Artisan::call('app:on-start', ['--force' => true]);
    Queue::assertPushed(RefreshWatchlistMarketDataJob::class, 1);
});
