<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Support/ResearchSourceFixtures.php';
require_once __DIR__.'/../Support/ResearchSourceNedoFixtures.php';

/**
 * UC-016 市場からの調査候補発見・確認 / NEDO追加Red / ADR-0032の次サイクル提案。
 * Public command, real MySQL and synthetic HTML; no parser/service mocks.
 * Green and production activation require separate human approval.
 */
beforeEach(function () {
    config(['cache.default' => 'database', 'research_sources' => ['S09' => uc016NedoDefinition()]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml(), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
    ]);
});

test('UC-016 市場からの調査候補発見・確認はNEDO結果列の相対絶対URLを公式資料と基準線に結ぶ', function (string $href) {
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([uc016NedoRow(['result_url' => $href])])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('baseline')->and($check->item_count)->toBe(1)->and($check->new_item_count)->toBe(0);
    $item = DB::table('research_source_items')->sole();
    $url = 'https://www.nedo.go.jp/koubo/CD3_199901.html';
    expect($item->source_key)->toBe('S09')->and($item->url)->toBe($url)
        ->and($item->url_hash)->toBe(hash('sha256', $url))->and($item->published_at)->toBeNull()
        ->and((bool) $item->is_baseline)->toBeTrue()->and($item->first_check_id)->toBe($check->id)
        ->and($item->disposition)->toBe('unreviewed')->and($item->title)->toContain('合成AI基盤研究開発事業')->toContain('結果 2026-09-09');
    expect(json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR))->toEqualCanonicalizing([
        'project_code' => 'P26013', 'project_name' => '合成AI基盤研究開発事業', 'result_date' => '2026-09-09',
    ]);
    expect($item->created_at)->toBe(now()->toDateTimeString());
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
})->with([
    'root relative' => ['/koubo/CD3_199901.html'],
    'directory relative' => ['CD3_199901.html'],
    'absolute official URL' => ['https://www.nedo.go.jp/koubo/CD3_199901.html'],
]);

test('UC-016 市場からの調査候補発見・確認はNEDO予告公募開始締切と表外のリンクを結果として保存しない', function () {
    // A CD3-looking URL outside the result column must not be admitted either.
    $row = str_replace('/koubo/CD1_199901.html', '/koubo/CD3_177777.html', uc016NedoRow());
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([$row])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    expect(DB::table('research_source_items')->pluck('url')->all())->toBe(['https://www.nedo.go.jp/koubo/CD3_199901.html']);
    expect(DB::table('research_source_checks')->sole()->item_count)->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認はNEDOの初回失敗後の最初の成功を基準線にする', function () {
    uc016SourceCheck('S09', ['result' => 'failed', 'http_status' => 503, 'started_at' => now()->subDays(2)]);

    $this->artisan('research:check-sources')->assertSuccessful();

    expect(DB::table('research_source_checks')->latest('id')->first()->result)->toBe('baseline');
    expect((bool) DB::table('research_source_items')->sole()->is_baseline)->toBeTrue();
});

test('UC-016 市場からの調査候補発見・確認はNEDOの全結果空欄を成功0件の基準線にする', function () {
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([uc016NedoRow(['result_url' => null])])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('baseline')->and($check->item_count)->toBe(0)->and($check->new_item_count)->toBe(0);
    $this->assertDatabaseCount('research_source_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はNEDO一覧構造の欠損を成功0件とせず失敗にする', function (string $body) {
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response($body),
    ]);

    Artisan::call('research:check-sources');

    $check = DB::table('research_source_checks')->sole();
    expect($check->result)->toBe('failed')->and($check->http_status)->toBe(200)
        ->and($check->error_message)->not->toBeNull()->and($check->error_message)->not->toContain('PRIVATE_LOGIN_BODY');
    $this->assertDatabaseCount('research_source_items', 0);
})->with([
    'missing table' => ['<html><body>PRIVATE_LOGIN_BODY</body></html>'],
    'missing result heading' => [str_replace('<th scope="col">結果</th>', '<th scope="col">変更された見出し</th>', uc016NedoHtml())],
    'wrong cell count after valid row' => [uc016NedoHtml([uc016NedoRow(), '<tr><td>【P26014】</td><td>不足した列</td></tr>'])],
]);

test('UC-016 市場からの調査候補発見・確認はNEDOの不正な結果URLを一部保存せず源全体の失敗にする', function (string $url) {
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([
            uc016NedoRow(), uc016NedoRow(['project_code' => 'P26014', 'result_url' => $url]),
        ])),
    ]);

    Artisan::call('research:check-sources');

    expect(DB::table('research_source_checks')->sole()->result)->toBe('failed');
    $this->assertDatabaseCount('research_source_items', 0);
    Http::assertSentCount(1);
})->with([
    'foreign host' => ['https://example.org/koubo/CD3_199902.html'],
    'application announcement CD2' => ['/koubo/CD2_199902.html'],
    'PDF attachment' => ['/content/199902.pdf'],
    'javascript URL' => ['javascript:alert(1)'],
]);

test('UC-016 市場からの調査候補発見・確認はNEDO結果の日付が不正なら一部保存せず失敗にする', function (string $date) {
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([
            uc016NedoRow(), uc016NedoRow(['result_url' => '/koubo/CD3_199902.html', 'result_date' => $date]),
        ])),
    ]);

    Artisan::call('research:check-sources');

    expect(DB::table('research_source_checks')->sole()->result)->toBe('failed');
    $this->assertDatabaseCount('research_source_items', 0);
})->with(['invalid calendar date' => ['2026-02-30'], 'unparseable date' => ['日付不明']]);

test('UC-016 市場からの調査候補発見・確認はNEDO結果URLの重複と並替で既存の処理状態や原情報を上書きしない', function () {
    $url = 'https://www.nedo.go.jp/koubo/CD3_199901.html';
    $checkId = uc016SourceCheck('S09', ['result' => 'baseline', 'started_at' => now()->subDays(2)]);
    $metadata = ['project_code' => 'P26013', 'project_name' => '記録時の事業名', 'result_date' => '2026-09-09'];
    $itemId = uc016SourceItem($checkId, ['source_key' => 'S09', 'url' => $url, 'title' => '記録時の題名',
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'published_at' => null, 'is_baseline' => true,
        'disposition' => 'ignored', 'reviewed_at' => now()->subDay()]);
    $before = DB::table('research_source_items')->find($itemId);
    $secondId = uc016SourceItem($checkId, ['source_key' => 'S09', 'url' => 'https://www.nedo.go.jp/koubo/CD3_199902.html', 'is_baseline' => true]);
    $secondBefore = DB::table('research_source_items')->find($secondId);
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([
            uc016NedoRow(['project_code' => 'P26014', 'result_url' => '/koubo/CD3_199902.html']),
            uc016NedoRow(['result_url' => $url, 'project_name' => '変更後の事業名']), uc016NedoRow(),
        ])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->result)->toBe('no_new')->and($check->item_count)->toBe(2)->and($check->new_item_count)->toBe(0);
    $this->assertDatabaseCount('research_source_items', 2);
    expect(DB::table('research_source_items')->find($itemId))->toEqual($before)
        ->and(DB::table('research_source_items')->find($secondId))->toEqual($secondBefore);
});

test('UC-016 市場からの調査候補発見・確認はNEDO後続一覧で増えた結果URLだけを新着にする', function () {
    $checkId = uc016SourceCheck('S09', ['result' => 'baseline', 'started_at' => now()->subDays(2)]);
    uc016SourceItem($checkId, ['source_key' => 'S09', 'url' => 'https://www.nedo.go.jp/koubo/CD3_199901.html', 'is_baseline' => true]);
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([
            uc016NedoRow(['result_url' => '/koubo/CD3_199902.html', 'project_code' => 'P26014']), uc016NedoRow(),
        ])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->result)->toBe('new_items')->and($check->item_count)->toBe(2)->and($check->new_item_count)->toBe(1);
    $new = DB::table('research_source_items')->where('url', 'https://www.nedo.go.jp/koubo/CD3_199902.html')->sole();
    expect((bool) $new->is_baseline)->toBeFalse()->and($new->first_check_id)->toBe($check->id)->and($new->published_at)->toBeNull();
    $this->assertDatabaseCount('research_source_items', 2);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はNEDOの長い題名を255文字に収め原事業名と結果日を保持する', function () {
    $name = str_repeat('合成研究事業', 60);
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([uc016NedoRow(['project_name' => $name])])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $item = DB::table('research_source_items')->sole();
    $metadata = json_decode($item->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect(mb_strlen($item->title))->toBeLessThanOrEqual(255)->and($item->title)->toContain('合成研究事業')->toContain('結果 2026-09-09');
    expect($metadata['project_name'])->toBe($name)->and($metadata['result_date'])->toBe('2026-09-09');
    expect(strlen($item->metadata))->toBeLessThanOrEqual(4096);
});

test('UC-016 市場からの調査候補発見・確認はNEDO必須付帯情報が4KB超なら切り捨てず全行失敗にする', function () {
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([
            uc016NedoRow(), uc016NedoRow(['result_url' => '/koubo/CD3_199902.html', 'project_name' => str_repeat('A', 5000)]),
        ])),
    ]);

    Artisan::call('research:check-sources');

    expect(DB::table('research_source_checks')->sole()->result)->toBe('failed');
    $this->assertDatabaseCount('research_source_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はNEDO一覧だけをHTMLとして1回取得し記事やPDFを追取得しない', function () {
    Artisan::call('research:check-sources');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://www.nedo.go.jp/koubo/2026_list_05_03.html'
        && $request->method() === 'GET' && str_contains(implode(',', $request->header('Accept')), 'text/html'));
});

test('UC-016 市場からの調査候補発見・確認は無効なNEDOを強制確認しても取得しない', function () {
    config(['research_sources.S09.enabled' => false]);

    $this->artisan('research:check-sources', ['--force' => true])->assertSuccessful();

    Http::assertNothingSent();
    Sleep::assertSleptTimes(0);
    $this->assertDatabaseCount('research_source_checks', 0);
});

test('UC-016 市場からの調査候補発見・確認はNEDOの失敗後も後続RSSの確認を続ける', function () {
    config(['research_sources.H02' => uc016SourceDefinition()]);
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response('<html>一覧欠損</html>'),
        'https://example.org/feed.xml' => Http::response(uc016SourceRss()),
    ]);

    Artisan::call('research:check-sources');

    expect(DB::table('research_source_checks')->where('source_key', 'S09')->sole()->result)->toBe('failed');
    expect(DB::table('research_source_checks')->where('source_key', 'H02')->sole()->result)->toBe('baseline');
    expect(DB::table('research_source_items')->sole()->source_key)->toBe('H02');
    Http::assertSentCount(2);
});

test('UC-016 市場からの調査候補発見・確認はNEDOのHTML文字参照を事業名の文字として保存し会社名を捏造しない', function () {
    $name = '合成AI & ロボット「研究」';
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([uc016NedoRow(['project_name' => $name])])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $metadata = json_decode(DB::table('research_source_items')->sole()->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata)->toEqualCanonicalizing(['project_code' => 'P26013', 'project_name' => $name, 'result_date' => '2026-09-09']);
});

test('UC-016 市場からの調査候補発見・確認はNEDO後続結果が空欄でも保存済み項目を消さず新着なしにする', function () {
    $checkId = uc016SourceCheck('S09', ['result' => 'baseline', 'started_at' => now()->subDays(2)]);
    $itemId = uc016SourceItem($checkId, ['source_key' => 'S09', 'url' => 'https://www.nedo.go.jp/koubo/CD3_199901.html', 'is_baseline' => true]);
    $before = DB::table('research_source_items')->find($itemId);
    uc016FakeSourceResponses([
        'https://www.nedo.go.jp/koubo/2026_list_05_03.html' => Http::response(uc016NedoHtml([uc016NedoRow(['result_url' => null])])),
    ]);

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->latest('id')->first();
    expect($check->result)->toBe('no_new')->and($check->item_count)->toBe(0)->and($check->new_item_count)->toBe(0);
    expect(DB::table('research_source_items')->find($itemId))->toEqual($before);
});
