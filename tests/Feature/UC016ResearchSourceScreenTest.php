<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\ResearchCandidate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ResearchSourceFixtures.php';

/**
 * UC-016 source-screen contract: checkSources() forces retrieval; useSourceItem(id)
 * prefills form and selectedSourceItemId, saveCandidate() records the linked item;
 * ignoreSourceItem(id)/restoreSourceItem(id) change disposition. Existing Candidate
 * Policy gates apply. Selection alone never creates a candidate or marks recorded.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    uc016FakeSourceResponses(['https://example.org/feed.xml' => Http::response(uc016SourceRss())]);
    Sleep::fake();
    config(['research_sources' => ['H01' => uc016SourceDefinition()]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 04:00:00', 'UTC'));
});

test('UC-016 市場からの調査候補発見・確認は源ごとの未確認と初回取得と新着なしを区別して表示する', function () {
    config(['research_sources' => [
        'H01' => uc016SourceDefinition(['name' => '未確認の源']),
        'H02' => uc016SourceDefinition(['name' => '初回の源']),
        'S09' => uc016SourceDefinition(['name' => '新着なしの源']),
    ]]);
    uc016SourceCheck('H02', ['result' => 'baseline']);
    uc016SourceCheck('S09', ['result' => 'no_new']);
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('未確認の源')->assertSee('未確認')
        ->assertSee('初回の源')->assertSee('初回取得')
        ->assertSee('新着なしの源')->assertSee('新着なし');
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は基準線と処理済みを未処理新着件数から除く', function () {
    $check = uc016SourceCheck('H01', ['result' => 'new_items', 'new_item_count' => 3]);
    uc016SourceItem($check);
    uc016SourceItem($check, ['url' => 'https://example.org/baseline', 'is_baseline' => true]);
    uc016SourceItem($check, ['url' => 'https://example.org/ignored', 'disposition' => 'ignored']);
    uc016SourceItem($check, ['url' => 'https://example.org/recorded', 'disposition' => 'recorded']);
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('新着あり')->assertSee('未処理の新着: 1');
});

test('UC-016 市場からの調査候補発見・確認は3回連続失敗と配信範囲超過を警告し源を停止しない', function () {
    uc016SourceCheck('H01', ['started_at' => now()->subDays(20), 'finished_at' => now()->subDays(20)]);
    foreach ([3, 2, 1] as $days) {
        uc016SourceCheck('H01', ['result' => 'failed', 'started_at' => now()->subDays($days),
            'finished_at' => now()->subDays($days), 'http_status' => 503, 'error_message' => 'HTTP 503']);
    }
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('失敗')->assertSee('HTTP 503')->assertSee('取得停止中の可能性')->assertSee('取りこぼしの可能性');
    expect(config('research_sources.H01.enabled'))->toBeTrue();
});

test('UC-016 市場からの調査候補発見・確認は成功後に連続失敗の警告を解除する', function () {
    foreach ([4, 3, 2] as $days) {
        uc016SourceCheck('H01', ['result' => 'failed', 'started_at' => now()->subDays($days), 'finished_at' => now()->subDays($days)]);
    }
    uc016SourceCheck('H01', ['result' => 'no_new', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertDontSee('取得停止中の可能性')->assertSee('新着なし');
});

test('UC-016 市場からの調査候補発見・確認は3回未満の連続失敗では取得停止の警告を出さない', function (array $results) {
    foreach ($results as $offset => $result) {
        uc016SourceCheck('H01', [
            'started_at' => now()->subDays(count($results) - $offset),
            'result' => $result,
            'http_status' => $result === 'failed' ? 503 : 200,
            'error_message' => $result === 'failed' ? 'HTTP 503' : null,
        ]);
    }

    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('試験用RSS')->assertSee('失敗')->assertDontSee('取得停止中の可能性');
})->with([
    'one failure' => [['no_new', 'failed']],
    'two failures' => [['no_new', 'failed', 'failed']],
    'success between failures' => [['failed', 'failed', 'failed', 'no_new', 'failed']],
]);

test('UC-016 市場からの調査候補発見・確認は成功確認から配信範囲を超えた場合だけ取りこぼしを警告する', function (int $ageInSeconds, bool $warn) {
    uc016SourceCheck('H01', ['started_at' => now()->subSeconds($ageInSeconds)]);
    uc016SourceCheck('H01', ['started_at' => now()->subHour(), 'result' => 'failed', 'http_status' => 503]);

    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('試験用RSS');
    if ($warn) {
        $component->assertSee('取りこぼしの可能性');
    } else {
        $component->assertDontSee('取りこぼしの可能性');
    }
})->with([
    'exactly 14 days' => [14 * 86400, false],
    '14 days plus one second' => [14 * 86400 + 1, true],
]);

test('UC-016 市場からの調査候補発見・確認は今すぐ確認で間隔内も取得する', function () {
    uc016SourceCheck('H01', ['started_at' => now()->subMinute()]);
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('checkSources')->assertHasNoErrors();
    Http::assertSentCount(1);
    expect(DB::table('research_source_checks')->latest('id')->first()->trigger)->toBe('manual');
});

test('UC-016 市場からの調査候補発見・確認は源の選択だけで候補を作らず保存時に発見経路と結ぶ', function () {
    $check = uc016SourceCheck();
    $itemId = uc016SourceItem($check);
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('useSourceItem', $itemId)
        ->assertSet('form.discovery_url', 'https://example.org/articles/old')
        ->assertSet('form.source_title', '試験の発表')->assertSet('form.publisher', '試験用RSS')
        ->assertSet('form.posted_on', '2026-10-09')->assertSet('form.announced_on', null)
        ->assertSet('selectedSourceItemId', $itemId);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseHas('research_source_items', ['id' => $itemId, 'disposition' => 'unreviewed']);
    $component->set('form.theme', 'フィジカルAI')->set('form.theme_relation', '供給網に関係する可能性')
        ->set('form.event_title', '試験の発表')->set('form.legal_name', '未同定株式会社')
        ->set('form.summary', '紹介資料。一次資料と上場主体は未確認。')->call('saveCandidate')->assertHasNoErrors();
    $item = DB::table('research_source_items')->find($itemId);
    expect($item->disposition)->toBe('recorded')->and($item->reviewed_at)->not->toBeNull()
        ->and($item->research_discovery_link_id)->toBe(DB::table('research_discovery_links')->sole()->id);
    $this->assertDatabaseCount('research_candidates', 1);
    $this->assertDatabaseHas('research_discovery_links', ['posted_on' => '2026-10-09']);
    $this->assertDatabaseHas('research_events', ['announced_on' => null]);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は公開日不明の源を選んでも投稿日を捏造しない', function () {
    $itemId = uc016SourceItem(uc016SourceCheck(), ['published_at' => null]);
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->set('form.posted_on', '2026-01-01')->call('useSourceItem', $itemId)
        ->assertSet('form.posted_on', null)->assertSet('form.announced_on', null);
    $this->assertDatabaseCount('research_candidates', 0);
});

test('UC-016 市場からの調査候補発見・確認は項目を対象外にし未処理へ戻しても項目を削除しない', function () {
    $itemId = uc016SourceItem(uc016SourceCheck());
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('ignoreSourceItem', $itemId)->assertHasNoErrors();
    $item = DB::table('research_source_items')->find($itemId);
    expect($item->disposition)->toBe('ignored')->and($item->reviewed_at)->not->toBeNull();
    $component->call('restoreSourceItem', $itemId)->assertHasNoErrors();
    $this->assertDatabaseHas('research_source_items', ['id' => $itemId, 'disposition' => 'unreviewed', 'reviewed_at' => null]);
    $this->assertDatabaseCount('research_source_items', 1);
    $this->assertDatabaseCount('research_candidates', 0);
});

test('UC-016 市場からの調査候補発見・確認は未認証者に情報源画面を開かせない', function () {
    $this->get('/candidate-research')->assertRedirect('/login');
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は権限を失った本人による情報源操作を拒否する', function (string $method, string $ability) {
    $itemId = uc016SourceItem(uc016SourceCheck());
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class);
    Gate::before(fn ($user, $requestedAbility, $arguments) => $requestedAbility === $ability && ($arguments[0] ?? null) === ResearchCandidate::class ? false : null);
    $args = $method === 'checkSources' ? [] : [$itemId];
    $component->call($method, ...$args)->assertForbidden();
    Http::assertNothingSent();
    $this->assertDatabaseHas('research_source_items', ['id' => $itemId, 'disposition' => 'unreviewed']);
})->with([
    'force check' => ['checkSources', 'viewAny'],
    'select' => ['useSourceItem', 'create'],
    'ignore' => ['ignoreSourceItem', 'create'],
    'restore' => ['restoreSourceItem', 'create'],
]);
