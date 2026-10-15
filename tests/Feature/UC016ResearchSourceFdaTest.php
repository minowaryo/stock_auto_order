<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Research\ResearchSourceItemService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Support/ResearchSourceFixtures.php';
require_once __DIR__.'/../Support/ResearchSourceFdaFixtures.php';

/**
 * UC-016 市場からの調査候補発見・確認 / FDA追加 / ADR-0032 Accepted (2026-10-11).
 * Public command, real MySQL and synthetic HTTP responses; no parser/service mocks.
 * Existing source-screen permission and disposition tests remain applicable.
 */
beforeEach(function () {
    config(['cache.default' => 'database', 'research_sources' => ['S10' => uc016FdaDefinition()]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([uc016FdaRow()]), 200, ['Content-Type' => 'text/csv']),
    ]);
});

test('UC-016 市場からの調査候補発見・確認はFDA各申請番号を公式資料URLと基準線に結ぶ', function (string $number, string $url) {
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([uc016FdaRow($number)])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('baseline')->and($check->item_count)->toBe(1)->and($check->new_item_count)->toBe(0);
    $item = DB::table('research_source_items')->sole();
    expect($item->url)->toBe($url)->and($item->url_hash)->toBe(hash('sha256', $url))
        ->and($item->published_at)->toBeNull()->and((bool) $item->is_baseline)->toBeTrue()
        ->and($item->first_check_id)->toBe($check->id)->and($item->disposition)->toBe('unreviewed')
        ->and($item->title)->toContain('Synthetic Medical Research Co.')->toContain('Synthetic Imaging Assistant')->toContain($number)
        ->toContain('決定')->toContain('2026-06-29');
    expect(mb_strlen($item->title))->toBeLessThanOrEqual(255);
    expect(json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR))->toEqualCanonicalizing([
        'company' => 'Synthetic Medical Research Co.',
        'device_name' => 'Synthetic Imaging Assistant',
        'submission_number' => $number,
        'date_of_final_decision' => '2026-06-29',
        'review_panel' => 'Radiology',
        'product_code' => 'QAS',
    ]);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
})->with([
    '510(k)' => ['K253628', 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfPMN/pmn.cfm?ID=K253628'],
    'De Novo' => ['DEN250057', 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfpmn/denovo.cfm?id=DEN250057'],
    'PMA' => ['P250008', 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfpma/pma.cfm?id=P250008'],
    'PMA supplement' => ['P130020/S005', 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfpma/pma.cfm?id=P130020S005'],
]);

test('UC-016 市場からの調査候補発見・確認はFDA CSVのBOMと引用されたカンマ改行を保持する', function () {
    $row = uc016FdaRow(overrides: ['Company' => 'Synthetic, Medical "Lab"', 'Device' => "Synthetic Device\nSecond Line"]);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([$row], bom: true)),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $metadata = json_decode(DB::table('research_source_items')->sole()->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata['company'])->toBe($row['Company'])->and($metadata['device_name'])->toBe($row['Device']);
});

test('UC-016 市場からの調査候補発見・確認はFDAの失敗後の最初の成功も基準線にする', function () {
    uc016SourceCheck('S10', ['result' => 'failed', 'http_status' => 503, 'started_at' => now()->subDays(8)]);

    $this->artisan('research:check-sources')->assertSuccessful();

    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('baseline');
    expect((bool) DB::table('research_source_items')->sole()->is_baseline)->toBeTrue();
});

test('UC-016 市場からの調査候補発見・確認は正規ヘッダーだけのFDA CSVを成功0件の基準線にする', function () {
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv()),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('baseline')->and($check->item_count)->toBe(0)->and($check->new_item_count)->toBe(0);
    $this->assertDatabaseCount('research_source_items', 0);
});

test('UC-016 市場からの調査候補発見・確認は壊れたFDA CSVやログインHTMLを源の失敗として部分保存しない', function (string $body) {
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response($body, 200, ['Content-Type' => 'text/csv']),
    ]);

    Artisan::call('research:check-sources');

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('failed')->and($check->http_status)->toBe(200)
        ->and($check->error_message)->not->toBeNull()->and($check->error_message)->not->toContain('PRIVATE_LOGIN_BODY');
    $this->assertDatabaseCount('research_source_items', 0);
    Http::assertSentCount(1);
})->with([
    'missing required column' => [uc016FdaCsv([['06/29/2026', 'K253628']], ['Date of Final Decision', 'Submission Number'])],
    'HTML login instead of CSV' => ['<html><body>PRIVATE_LOGIN_BODY<input name="password"></body></html>'],
    'invalid submission after valid row' => [uc016FdaCsv([uc016FdaRow(), uc016FdaRow('UNKNOWN123')])],
    'invalid decision date after valid row' => [uc016FdaCsv([uc016FdaRow(), uc016FdaRow('DEN250057', ['Date of Final Decision' => '02/30/2026'])])],
    'empty company after valid row' => [uc016FdaCsv([uc016FdaRow(), uc016FdaRow('DEN250057', ['Company' => ''])])],
    'row with wrong column count' => [uc016FdaCsv([uc016FdaRow(), ['06/29/2026', 'DEN250057']])],
]);

test('UC-016 市場からの調査候補発見・確認はFDAの重複番号と行順入替で項目や本人の処理状態を増減しない', function () {
    $url = 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfPMN/pmn.cfm?ID=K253628';
    $checkId = uc016SourceCheck('S10', ['result' => 'baseline', 'started_at' => now()->subDays(8)]);
    $itemId = uc016SourceItem($checkId, ['source_key' => 'S10', 'url' => $url, 'title' => 'Previously recorded', 'disposition' => 'ignored', 'is_baseline' => true]);
    uc016SourceItem($checkId, ['source_key' => 'S10', 'url' => 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfpmn/denovo.cfm?id=DEN250057', 'is_baseline' => true]);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([uc016FdaRow('DEN250057'), uc016FdaRow(), uc016FdaRow('DEN250057')])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('no_new');
    $this->assertDatabaseCount('research_source_items', 2);
    expect(DB::table('research_source_items')->where('url', $url)->sole()->id)->toBe($itemId)
        ->and(DB::table('research_source_items')->where('url', $url)->sole()->disposition)->toBe('ignored');
});

test('UC-016 市場からの調査候補発見・確認はFDA初回一覧で古い行より決定日の新しい行を先に表示する', function () {
    $actor = User::factory()->create();
    uc016FakeSourceResponses([
        // The official CSV is newest first, while the shared screen sorts IDs descending.
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([
            uc016FdaRow('K253628'),
            uc016FdaRow('DEN250057', ['Date of Final Decision' => '03/01/2025']),
            uc016FdaRow('P130020/S005', ['Date of Final Decision' => '02/01/2020']),
        ])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $items = app(ResearchSourceItemService::class)->items($actor)->items();
    expect(array_map(fn ($item) => $item->metadata['date_of_final_decision'], $items))
        ->toBe(['2026-06-29', '2025-03-01', '2020-02-01']);
    foreach ($items as $item) {
        expect($item->published_at)->toBeNull();
        expect($item->created_at->toDateTimeString())->toBe(now()->toDateTimeString())
            ->and($item->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());
    }
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDAの後続CSVで増えた申請番号だけを新着にする', function () {
    $checkId = uc016SourceCheck('S10', ['result' => 'baseline', 'started_at' => now()->subDays(8)]);
    uc016SourceItem($checkId, ['source_key' => 'S10', 'url' => 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfPMN/pmn.cfm?ID=K253628', 'is_baseline' => true]);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([uc016FdaRow('DEN250057'), uc016FdaRow()])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->result)->toBe('new_items')->and($check->new_item_count)->toBe(1)->and($check->item_count)->toBe(2);
    $this->assertDatabaseCount('research_source_items', 2);
    $new = DB::table('research_source_items')->where('url', 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfpmn/denovo.cfm?id=DEN250057')->sole();
    expect((bool) $new->is_baseline)->toBeFalse()->and($new->first_check_id)->toBe($check->id);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はFDAの長い題名を255文字に収め原フィールドを4KB以内で保持する', function () {
    $row = uc016FdaRow(overrides: ['Company' => str_repeat('試験会社', 60), 'Device' => str_repeat('試験製品', 60)]);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([$row])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $item = DB::table('research_source_items')->sole();
    $metadata = json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect(mb_strlen($item->title))->toBeLessThanOrEqual(255)->and($metadata['company'])->toBe($row['Company'])
        ->and($metadata['device_name'])->toBe($row['Device']);
    expect($item->title)->toContain('試験会社')->toContain('試験製品')->toContain('K253628')->toContain('決定')->toContain('2026-06-29');
    expect(strlen($item->metadata))->toBeLessThanOrEqual(4096);
    expect(strlen(json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(4096);
});

test('UC-016 市場からの調査候補発見・確認はFDAの境界付近の付帯情報をMySQL保存後も4KB以内で保持する', function () {
    $row = uc016FdaRow(overrides: ['Company' => str_repeat('A', 3850)]);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([$row])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $item = DB::table('research_source_items')->sole();
    expect(strlen($item->metadata))->toBeLessThanOrEqual(4096);
    $metadata = json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata['company'])->toBe($row['Company'])->and($metadata['device_name'])->toBe($row['Device']);
});

test('UC-016 市場からの調査候補発見・確認はFDA既定設定にCSV直URLと168時間30秒の間隔を持つ', function () {
    $sources = require config_path('research_sources.php');

    expect($sources['S10']['url'])->toBe('https://www.fda.gov/media/178541/download?attachment=')
        ->and($sources['S10']['method'])->toBe('file')->and($sources['S10']['min_interval_hours'])->toBe(168)
        ->and($sources['S10']['min_request_interval_seconds'] ?? null)->toBe(30);
});

test('UC-016 市場からの調査候補発見・確認は無効なFDAを強制確認しても取得しない', function () {
    config(['research_sources.S10.enabled' => false]);

    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();

    Sleep::assertSleptTimes(0);
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 0);
});

test('UC-016 市場からの調査候補発見・確認はFDA付帯情報が4KBを超える場合は切り捨てず全行保存を失敗にする', function () {
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016FdaCsv([uc016FdaRow(), uc016FdaRow('DEN250057', ['Company' => str_repeat('A', 5000)])])),
    ]);

    Artisan::call('research:check-sources');

    expect(DB::table('research_source_checks')->sole()->result)->toBe('failed');
    $this->assertDatabaseCount('research_source_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はFDAをCSVとして1回だけ取得し個別資料を追取得しない', function () {
    Artisan::call('research:check-sources');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://www.fda.gov/media/178541/download?attachment='
        && $request->method() === 'GET' && str_contains(implode(',', $request->header('Accept')), 'text/csv'));
});

test('UC-016 市場からの調査候補発見・確認はFDAを強制確認しても直近失敗試行から30秒まで取得前に待つ', function () {
    $initialNow = now()->toImmutable();
    uc016SourceCheck('S10', ['started_at' => now()->subSeconds(10), 'result' => 'failed', 'http_status' => 503]);
    $requestAt = null;
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => function () use (&$requestAt) {
            $requestAt = now()->toImmutable();

            return Http::response(uc016FdaCsv([uc016FdaRow()]));
        },
    ]);

    Artisan::call('research:check-sources', ['--force' => true]);

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 21000);
    expect($requestAt->equalTo($initialNow->addSeconds(21)))->toBeTrue();
    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->trigger)->toBe('manual')->and($check->started_at)->toBe($initialNow->addSeconds(21)->toDateTimeString())
        ->and($check->result)->toBe('baseline');
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDAの保存時刻から30秒境界で精度分の1秒を待つ', function () {
    uc016SourceCheck('S10', ['started_at' => now()->subSeconds(30)]);

    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 1000);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDA実試行のミリ秒がDBで落ちても強制確認の実間隔を30秒以上に保つ', function (int $firstStatus) {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:00.900', 'UTC'));
    $requestTimes = [];
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => function () use (&$requestTimes, $firstStatus) {
            $requestTimes[] = now()->toImmutable();
            $status = count($requestTimes) === 1 ? $firstStatus : 200;

            return Http::response(uc016FdaCsv([uc016FdaRow()]), $status, ['Content-Type' => 'text/csv']);
        },
    ]);
    $this->artisan('research:check-sources')->assertExitCode($firstStatus === 200 ? 0 : 1);
    expect($requestTimes[0]->format('Y-m-d H:i:s.u'))->toBe('2026-10-11 04:00:00.900000');
    // MySQL timestamps have zero fractional precision: test the real round-trip,
    // rather than inserting an already truncated fixture as the prior attempt.
    expect(DB::table('research_source_checks')->sole()->started_at)->toBe('2026-10-11 04:00:00');
    Sleep::assertSleptTimes(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:10.100', 'UTC'));

    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();

    expect($requestTimes[0]->diffInSeconds($requestTimes[1]))->toBeGreaterThanOrEqual(30);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 20900);
    expect($requestTimes[1]->format('Y-m-d H:i:s.u'))->toBe('2026-10-11 04:00:31.000000');
    $this->assertDatabaseCount('research_source_checks', 2);
    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->started_at)->toBe('2026-10-11 04:00:31')->and($check->trigger)->toBe('manual')
        ->and($check->result)->toBe($firstStatus === 200 ? 'no_new' : 'baseline');
    Http::assertSentCount(2);
})->with(['after successful baseline' => [200], 'after failed fetch' => [503]]);

test('UC-016 市場からの調査候補発見・確認はFDAの取得履歴INSERT遅延後も完了時刻から実間隔30秒を守る', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:00.900', 'UTC'));
    $insertDelayed = false;
    DB::listen(function ($query) use (&$insertDelayed): void {
        if (! $insertDelayed && str_starts_with(strtolower($query->sql), 'insert into `research_source_checks`')) {
            $insertDelayed = true;
            // Real database write; simulate only elapsed time before the HTTP request.
            $this->travelTo(now()->toImmutable()->addMilliseconds(1200));
        }
    });
    $requestTimes = [];
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => function () use (&$requestTimes) {
            $requestTimes[] = now()->toImmutable();

            return Http::response(uc016FdaCsv([uc016FdaRow()]), 200, ['Content-Type' => 'text/csv']);
        },
    ]);
    $this->artisan('research:check-sources')->assertSuccessful();
    expect($insertDelayed)->toBeTrue()->and($requestTimes[0]->format('Y-m-d H:i:s.u'))->toBe('2026-10-11 04:00:02.100000');
    $firstCheck = DB::table('research_source_checks')->sole();
    expect($firstCheck->started_at)->toBe('2026-10-11 04:00:00')->and($firstCheck->finished_at)->toBe('2026-10-11 04:00:02');
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:10.100', 'UTC'));

    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();

    expect($requestTimes[0]->diffInSeconds($requestTimes[1]))->toBeGreaterThanOrEqual(30);
    expect($requestTimes[1]->format('Y-m-d H:i:s.u'))->toBe('2026-10-11 04:00:33.000000');
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 22900);
    $this->assertDatabaseCount('research_source_checks', 2);
    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('no_new');
    Http::assertSentCount(2);
});

test('UC-016 市場からの調査候補発見・確認はFDAの保存時刻から31秒境界なら精度分の待機も追加しない', function () {
    uc016SourceCheck('S10', ['started_at' => now()->subSeconds(31)]);

    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();

    Sleep::assertSleptTimes(0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は源間1.5秒とFDAの30秒残り待機を二重に加算しない', function () {
    config(['research_sources' => ['H02' => uc016SourceDefinition(), 'S10' => uc016FdaDefinition()]]);
    $initialNow = now()->toImmutable();
    uc016SourceCheck('S10', ['started_at' => now()->subSeconds(10)]);
    $requestAt = null;
    uc016FakeSourceResponses([
        'https://example.org/feed.xml' => Http::response(uc016SourceRss()),
        'https://www.fda.gov/media/178541/download?attachment=' => function () use (&$requestAt) {
            $requestAt = now()->toImmutable();

            return Http::response(uc016FdaCsv([uc016FdaRow()]));
        },
    ]);

    Artisan::call('research:check-sources', ['--force' => true]);

    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 1500);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 19500);
    expect($requestAt->equalTo($initialNow->addSeconds(21)))->toBeTrue();
    Http::assertSentCount(2);
});

test('UC-016 市場からの調査候補発見・確認は源固有待機だけで全体120秒を使い切る場合に取得履歴を作らない', function () {
    config(['research_sources.S10.min_request_interval_seconds' => 120]);
    uc016SourceCheck('S10', ['started_at' => now(), 'result' => 'failed']);

    $status = Artisan::call('research:check-sources', ['--force' => true]);

    Sleep::assertSleptTimes(0);
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 1);
    expect($status)->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認はFDAの168時間未満の起動で待機と外部アクセスをしない', function () {
    uc016SourceCheck('S10', ['started_at' => now()->subHours(168)->addSecond()]);

    $this->artisan('research:check-sources')->assertSuccessful();

    Sleep::assertSleptTimes(0);
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 1);
});

test('UC-016 市場からの調査候補発見・確認はFDAの168時間境界で通常取得を再開する', function () {
    uc016SourceCheck('S10', ['started_at' => now()->subHours(168)]);

    $this->artisan('research:check-sources')->assertSuccessful();

    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('new_items');
    Http::assertSentCount(1);
    Sleep::assertSleptTimes(0);
});

test('UC-016 市場からの調査候補発見・確認はFDA CSVサイズ超過を共通取得層で打ち切る', function () {
    config(['research_sources.S10.max_response_bytes' => 32]);

    Artisan::call('research:check-sources');

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('failed')->and($check->error_message)->toContain('サイズ');
    $this->assertDatabaseCount('research_source_items', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDA CSV失敗でも次のRSS情報源を確認する', function () {
    config(['research_sources' => ['S10' => uc016FdaDefinition(), 'H02' => uc016SourceDefinition()]]);
    uc016FakeSourceResponses([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response('NOT_A_CSV'),
        'https://example.org/feed.xml' => Http::response(uc016SourceRss()),
    ]);

    Artisan::call('research:check-sources');

    $this->assertDatabaseHas('research_source_checks', ['source_key' => 'S10', 'result' => 'failed']);
    $this->assertDatabaseHas('research_source_checks', ['source_key' => 'H02', 'result' => 'baseline']);
    $this->assertDatabaseMissing('research_source_items', ['source_key' => 'S10']);
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 1500);
});

test('UC-016 市場からの調査候補発見・確認は専用ロック中にFDA強制確認も待機と外部アクセスをしない', function () {
    $lock = Cache::store('database')->lock('research-source-check', 600);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();
        Sleep::assertSleptTimes(0);
        Http::assertNothingSent();
        $this->assertDatabaseCount('research_source_checks', 0);
    } finally {
        $lock->release();
    }
});
