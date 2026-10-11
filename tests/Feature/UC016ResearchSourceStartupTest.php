<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * UC-016「市場からの調査候補発見・確認」情報源の起動時確認 / ADR-0031。
 * Red contract: research_sources is keyed by source ID, with name, method, url,
 * enabled, optional min_interval_hours, max_response_bytes and coverage_days.
 * All network responses are synthetic. DB assertions use the approved schema.
 */
require_once __DIR__.'/../Support/ResearchSourceFixtures.php';

beforeEach(function () {
    config(['cache.default' => 'database']);
    Http::preventStrayRequests();
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response(uc016SourceRss(), 200, ['Content-Type' => 'application/rss+xml'])]);
    Sleep::fake();
    config(['research_sources' => ['H01' => uc016SourceDefinition()]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 04:00:00', 'UTC'));
});

test('UC-016 市場からの調査候補発見・確認は初回成功を基準線にして候補を自動作成しない', function (string $url) {
    $feed = uc016SourceRss([$url]);
    config(['research_sources.H01.max_response_bytes' => strlen($feed)]);
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response($feed)]);
    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('baseline')->and($check->trigger)->toBe('startup')
        ->and($check->item_count)->toBe(1)->and($check->new_item_count)->toBe(0)
        ->and($check->finished_at)->not->toBeNull();
    $item = DB::table('research_source_items')->sole();
    expect((bool) $item->is_baseline)->toBeTrue()->and($item->first_check_id)->toBe($check->id)
        ->and($item->url)->toBe($url)->and($item->url_hash)->toBe(hash('sha256', $item->url));
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
})->with(['https://example.org/articles/old', 'http://example.org/articles/old']);

test('UC-016 市場からの調査候補発見・確認は初回失敗の後の最初の成功も基準線にする', function () {
    uc016SourceCheck('H01', ['result' => 'failed', 'http_status' => 503]);
    $this->artisan('research:check-sources')->assertSuccessful();
    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('baseline');
});

test('UC-016 市場からの調査候補発見・確認は24時間未満の再起動で外部アクセスと試行行を増やさない', function () {
    uc016SourceCheck('H01', ['started_at' => now()->subHours(24)->addSecond()]);
    $this->artisan('research:check-sources')->assertSuccessful();
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 1);
});

test('UC-016 市場からの調査候補発見・確認は最後の失敗試行から24時間未満も再取得しない', function () {
    uc016SourceCheck('H01', ['result' => 'failed', 'started_at' => now()->subHour(), 'http_status' => 503]);
    $this->artisan('research:check-sources')->assertSuccessful();
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 1);
});

test('UC-016 市場からの調査候補発見・確認は最低間隔ちょうどで再取得できる', function () {
    uc016SourceCheck('H01', ['started_at' => now()->subHours(24)]);
    $this->artisan('research:check-sources')->assertSuccessful();
    Http::assertSentCount(1);
    $this->assertDatabaseCount('research_source_checks', 2);
});

test('UC-016 市場からの調査候補発見・確認はS10の168時間間隔を設定から適用する', function () {
    config(['research_sources' => ['S10' => uc016SourceDefinition(['min_interval_hours' => 168])]]);
    uc016SourceCheck('S10', ['started_at' => now()->subHours(168)->addSecond()]);
    $this->artisan('research:check-sources')->assertSuccessful();
    Http::assertNothingSent();
    $this->travel(1)->seconds();
    $this->artisan('research:check-sources')->assertSuccessful();
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はforceで最低間隔を無視し手動試行を記録する', function () {
    uc016SourceCheck('H01', ['started_at' => now()->subMinute()]);
    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();
    Http::assertSentCount(1);
    expect(DB::table('research_source_checks')->latest('id')->first()->trigger)->toBe('manual');
});

test('UC-016 市場からの調査候補発見・確認は停止した情報源をforceでも取得しない', function () {
    config(['research_sources' => ['H01' => uc016SourceDefinition(['enabled' => false])]]);
    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 0);
});

test('UC-016 市場からの調査候補発見・確認は同じURLを重複登録せず新URLだけ新着に数える', function () {
    $first = uc016SourceCheck('H01', ['result' => 'baseline']);
    uc016SourceItem($first, ['is_baseline' => true]);
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response(uc016SourceRss([
        'https://example.org/articles/old', 'https://example.org/articles/new', 'https://example.org/articles/new',
    ]))]);
    $this->artisan('research:check-sources')->assertSuccessful();
    $this->assertDatabaseCount('research_source_items', 2);
    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->result)->toBe('new_items')->and($check->new_item_count)->toBe(1);
    expect((bool) DB::table('research_source_items')->where('url', 'https://example.org/articles/new')->first()->is_baseline)->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は確認済みURLだけなら新着なしを記録する', function () {
    $first = uc016SourceCheck('H01', ['result' => 'baseline']);
    uc016SourceItem($first, ['disposition' => 'ignored', 'reviewed_at' => now()->subDay()]);
    $this->artisan('research:check-sources')->assertSuccessful();
    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('no_new');
    $this->assertDatabaseCount('research_source_items', 1);
    expect(DB::table('research_source_items')->sole()->disposition)->toBe('ignored');
});

test('UC-016 市場からの調査候補発見・確認は公開日時不明を取得日時で補わず付帯情報だけ保存する', function () {
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response(uc016SourceRss(dated: false))]);
    $this->artisan('research:check-sources')->assertSuccessful();
    $item = DB::table('research_source_items')->sole();
    $metadata = json_encode(json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    expect($item->published_at)->toBeNull()->and($metadata)->toContain('試験著者')
        ->and($metadata)->toContain('AI')->and($metadata)->not->toContain('ARTICLE_BODY_MUST_NOT_BE_STORED');
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はHTTPエラーを源ごとに記録し次の源を確認する', function () {
    config(['research_sources' => [
        'H01' => uc016SourceDefinition(), 'H02' => uc016SourceDefinition(['url' => 'https://example.org/next.xml']),
    ]]);
    uc016FakeSourceResponses([
        'https://example.org/feed.xml' => Http::response('PRIVATE_RESPONSE_BODY', 503),
        'https://example.org/next.xml' => Http::response(uc016SourceRss()),
    ]);
    Artisan::call('research:check-sources');
    $failed = DB::table('research_source_checks')->where('source_key', 'H01')->sole();
    expect($failed->result)->toBe('failed')->and($failed->http_status)->toBe(503)
        ->and($failed->error_message)->not->toBeNull()->and($failed->error_message)->not->toContain('PRIVATE_RESPONSE_BODY');
    $this->assertDatabaseHas('research_source_checks', ['source_key' => 'H02', 'result' => 'baseline']);
    Http::assertSentCount(2);
});

test('UC-016 市場からの調査候補発見・確認はタイムアウトを記録し秘密付き例外URLを保存しない', function () {
    uc016FakeSourceResponses(['https://example.org/feed.xml' => fn () => throw new ConnectionException('timeout https://example.org/feed?token=DO_NOT_STORE')]);
    Artisan::call('research:check-sources');
    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('failed')->and($check->error_message)->not->toBeNull()
        ->and($check->error_message)->not->toContain('DO_NOT_STORE');
    $this->assertDatabaseCount('research_source_items', 0);
});

test('UC-016 市場からの調査候補発見・確認は応答サイズ超過を失敗として項目を保存しない', function (string $method) {
    config(['research_sources' => ['H01' => uc016SourceDefinition(['method' => $method, 'max_response_bytes' => 128])]]);
    Artisan::call('research:check-sources');
    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('failed')->and($check->error_message)->toContain('サイズ');
    $this->assertDatabaseCount('research_source_items', 0);
})->with(['rss', 'file']);

test('UC-016 市場からの調査候補発見・確認は専用ロック中に外部アクセスしない', function () {
    $lock = Cache::lock('research-source-check', 600);
    expect($lock->get())->toBeTrue();
    try {
        Artisan::call('research:check-sources');
        Http::assertNothingSent();
        $this->assertDatabaseCount('research_source_checks', 0);
    } finally {
        $lock->release();
    }
});

test('UC-016 市場からの調査候補発見・確認は価格取得ロックに巻き込まれず取得する', function () {
    $lock = Cache::lock('price-fetch', 600);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('research:check-sources')->assertSuccessful();
        Http::assertSentCount(1);
    } finally {
        $lock->release();
    }
});

test('UC-016 市場からの調査候補発見・確認は付帯情報を4KB以内に収め記事本文を保存しない', function () {
    $feed = str_replace('試験著者', str_repeat('A', 5000), uc016SourceRss());
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response($feed)]);
    $this->artisan('research:check-sources')->assertSuccessful();
    $item = DB::table('research_source_items')->sole();
    expect($item->metadata === null || strlen($item->metadata) <= 4096)->toBeTrue();
    expect($item->metadata ?? '')->not->toContain('ARTICLE_BODY_MUST_NOT_BE_STORED');
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は情報源のリクエストの間に1.5秒空ける', function () {
    config(['research_sources' => [
        'H01' => uc016SourceDefinition(),
        'H02' => uc016SourceDefinition(['url' => 'https://example.org/next.xml']),
    ]]);
    uc016FakeSourceResponses([
        'https://example.org/feed.xml' => Http::response(uc016SourceRss()),
        'https://example.org/next.xml' => Http::response(uc016SourceRss()),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 1500);
});

test('UC-016 市場からの調査候補発見・確認は全体2分の上限を過ぎたら残りの源へアクセスしない', function () {
    config(['research_sources' => [
        'H01' => uc016SourceDefinition(),
        'H02' => uc016SourceDefinition(['url' => 'https://example.org/next.xml']),
    ]]);
    uc016FakeSourceResponses(['https://example.org/feed.xml' => function () {
        $this->travel(121)->seconds();

        return Http::response(uc016SourceRss());
    }]);

    Artisan::call('research:check-sources');

    Http::assertSentCount(1);
    $this->assertDatabaseCount('research_source_checks', 1);
    $this->assertDatabaseMissing('research_source_checks', ['source_key' => 'H02']);
});

test('UC-016 市場からの調査候補発見・確認は不正な項目URLを保存も取得もしない', function (string $url) {
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response(uc016SourceRss([$url]))]);

    // The source may reject the entire feed or exclude the bad item; the UC does
    // not choose that policy. Both must keep invalid URLs out of storage.
    Artisan::call('research:check-sources');

    $this->assertDatabaseCount('research_source_items', 0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://example.org/feed.xml');
})->with([
    'script scheme' => ['javascript:alert(1)'],
    'local file' => ['file:///tmp/source-private'],
    'ftp scheme' => ['ftp://example.org/article'],
    'missing host' => ['https://'],
    'too long' => ['https://example.org/'.str_repeat('a', 2050)],
]);
