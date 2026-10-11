<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchlistItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * UC-016「市場からの調査候補発見・確認」2026-10-09 再レビュー指摘の追加 Red テスト。
 *
 * Gate 4 で確認する契約:
 * - 週・既定の確認日は表示用タイムゾーン（config('app.display_timezone')）で区切る。
 * - 上場先の銘柄コードは前後空白を除いて大文字にし、日本株は4桁（英字を含む新コード可）、
 *   米国株は英大文字で始まるティッカー（.と-を許可）だけを受け付ける。「7203.T」等は拒否。
 * - 「確認済み上場企業」は元発表が確認済みの候補だけを数え、同じ上場先（市場＋コード）は1社とする。
 * - 「過去の発表」は統合済みの転載を除いた元発表の件数で数える。
 * - 一覧の照合結果は受け渡し履歴ではなく、最新のウォッチリスト・保有スナップショットと
 *   上場先から表示する（照合結果: 未登録／ウォッチリスト登録済み／保有中／照合未実施）。
 * - 「監視未登録」の絞り込みは、★OFFの既存行へ関連付けただけの候補を残す。
 * - 監視登録済みの候補の元発表を統合すると要再確認にする。統合済みの元発表IDへの関連付けは拒否する。
 * - useExistingEvent(id) / useExistingEntity(id) で既存記録の候補を一操作で選べる。
 */
function uc016FollowupFixture(array $o = []): array
{
    $now = $o['created_at'] ?? now();
    $eventId = $o['event_id'] ?? DB::table('research_events')->insertGetId([
        'title' => $o['event_title'] ?? '元発表',
        'original_url' => 'https://example.org/press/a',
        'original_publisher' => '発表企業',
        'announced_on' => $o['announced_on'] ?? '2026-09-30',
        'verification_status' => $o['verification_status'] ?? 'verified',
        'verified_on' => ($o['verification_status'] ?? 'verified') === 'verified' ? '2026-10-04' : null,
        'merged_into_event_id' => $o['merged_into_event_id'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if (! isset($o['event_id'])) {
        $url = $o['discovery_url'] ?? 'https://example.org/articles/'.$eventId;
        DB::table('research_discovery_links')->insert([
            'research_event_id' => $eventId, 'route_type' => 'manual', 'url' => $url,
            'url_hash' => hash('sha256', $url), 'source_title' => '紹介記事', 'publisher' => '紹介元',
            'checked_on' => '2026-10-04', 'summary' => '要約', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $entityId = DB::table('research_entities')->insertGetId([
        'legal_name' => $o['legal_name'] ?? '上場ロボット株式会社',
        'identification_status' => $o['identification_status'] ?? 'identified',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ($o['listings'] ?? [['jp', '8888']] as [$market, $code]) {
        DB::table('research_entity_listings')->insert([
            'research_entity_id' => $entityId, 'market' => $market, 'symbol_code' => $code,
            'listed_entity_name' => '上場ロボット株式会社', 'source_url' => 'https://example.org/ir',
            'confirmed_on' => '2026-10-04', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $candidateId = DB::table('research_candidates')->insertGetId([
        'research_event_id' => $eventId, 'research_entity_id' => $entityId,
        'theme' => 'フィジカルAI', 'theme_relation' => '供給網に関係する', 'entity_role' => '供給者',
        'status' => $o['status'] ?? 'investigating', 'lock_version' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('research_candidate_revisions')->insert([
        'research_candidate_id' => $candidateId, 'lock_version' => 1, 'change_type' => 'created',
        'payload' => json_encode(['candidate_id' => $candidateId], JSON_THROW_ON_ERROR), 'created_at' => $now,
    ]);

    return ['event_id' => $eventId, 'entity_id' => $entityId, 'candidate_id' => $candidateId];
}

function uc016FollowupBaseline(): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
        'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $other = Holding::create([
        'symbol_code' => '9999', 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '照合用別銘柄', 'first_detected_at' => now(),
    ]);
    WatchlistItem::create([
        'holding_id' => $other->id, 'source' => 'rakuten_favorites_csv',
        'last_seen_in_csv_at' => now(), 'registered_at' => now(),
    ]);

    return $snapshot;
}

function uc016FollowupHolding(string $code = '8888'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '上場ロボット株式会社', 'first_detected_at' => now(),
    ]);
}

test('UC-016 市場からの調査候補発見・確認は表示用タイムゾーンの週で今週の新規発見を数える', function () {
    config(['app.display_timezone' => 'Asia/Manila']);
    // 2026-10-12 (Mon) 01:00 in Manila = 2026-10-11 (Sun) 17:00 UTC
    $this->travelTo(CarbonImmutable::parse('2026-10-11 17:00:00', 'UTC'));
    uc016FollowupFixture(['announced_on' => '2026-10-12']);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->viewData('stats');

    expect($stats['this_week_new_discoveries'])->toBe(1)
        ->and($stats['past_announcements'])->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は確認日の既定値を表示用タイムゾーンの日付にする', function () {
    config(['app.display_timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 17:00:00', 'UTC'));

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->assertSet('form.checked_on', '2026-10-12');
});

test('UC-016 市場からの調査候補発見・確認は取引所接尾辞付きなど形式外の銘柄コードを上場先に保存しない', function () {
    $ids = uc016FollowupFixture(['listings' => []]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('editCandidate', $ids['candidate_id'])
        ->set('listingForm', [
            'market' => 'jp', 'symbol_code' => '7203.T', 'listed_entity_name' => '例示自動車',
            'source_url' => 'https://example.org/ir', 'confirmed_on' => '2026-10-04',
        ])
        ->call('addListing')
        ->assertHasErrors(['listingForm.symbol_code']);

    expect(DB::table('research_entity_listings')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は銘柄コードの空白と小文字を正規化して保存する', function () {
    $ids = uc016FollowupFixture(['listings' => []]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('editCandidate', $ids['candidate_id'])
        ->set('listingForm', [
            'market' => 'us', 'symbol_code' => ' nbis ', 'listed_entity_name' => 'Nebius Group',
            'source_url' => 'https://example.org/ir', 'confirmed_on' => '2026-10-04',
        ])
        ->call('addListing')
        ->assertHasNoErrors()
        ->call('editCandidate', $ids['candidate_id'])
        ->set('listingForm', [
            'market' => 'jp', 'symbol_code' => '523a', 'listed_entity_name' => 'セイワHD',
            'source_url' => 'https://example.org/ir', 'confirmed_on' => '2026-10-04',
        ])
        ->call('addListing')
        ->assertHasNoErrors();

    expect(DB::table('research_entity_listings')->orderBy('id')->pluck('symbol_code')->all())
        ->toBe(['NBIS', '523A']);
});

test('UC-016 市場からの調査候補発見・確認は元発表が未確認の候補を確認済み上場企業に数えない', function () {
    uc016FollowupFixture(['verification_status' => 'unverified', 'listings' => [['jp', '1111']]]);
    uc016FollowupFixture(['listings' => [['jp', '2222']]]);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->viewData('stats');

    expect($stats['verified_listed_entities'])->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は法人名の表記が違っても同じ上場先を一社と数える', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));
    uc016FollowupFixture(['legal_name' => 'メルカリ', 'announced_on' => '2026-10-06', 'listings' => [['jp', '4385']]]);
    uc016FollowupFixture(['legal_name' => '株式会社メルカリ', 'announced_on' => '2026-10-07', 'listings' => [['jp', '4385']]]);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->viewData('stats');

    expect($stats['verified_listed_entities'])->toBe(1)
        ->and($stats['this_week_new_discoveries'])->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は統合済みの転載を過去の発表に重ねて数えない', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));
    $original = uc016FollowupFixture(['announced_on' => '2026-08-01', 'legal_name' => '甲社', 'listings' => [['jp', '1111']]]);
    uc016FollowupFixture([
        'announced_on' => '2026-08-01', 'legal_name' => '乙社', 'listings' => [['jp', '2222']],
        'merged_into_event_id' => $original['event_id'],
    ]);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->viewData('stats');

    expect($stats['original_events'])->toBe(1)
        ->and($stats['past_announcements'])->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は一覧に最新の登録と保有の照合結果を表示する', function () {
    $snapshot = uc016FollowupBaseline();
    uc016FollowupFixture(['legal_name' => '未登録社', 'listings' => [['jp', '1111']]]);
    uc016FollowupFixture(['legal_name' => '登録済み社', 'listings' => [['jp', '2222']]]);
    uc016FollowupFixture(['legal_name' => '保有社', 'listings' => [['jp', '3333']]]);
    uc016FollowupFixture(['legal_name' => '同定未了社', 'identification_status' => 'unidentified', 'listings' => []]);
    WatchlistItem::create([
        'holding_id' => uc016FollowupHolding('2222')->id, 'source' => 'rakuten_favorites_csv',
        'is_starred' => true, 'last_seen_in_csv_at' => now(), 'registered_at' => now(),
    ]);
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id, 'holding_id' => uc016FollowupHolding('3333')->id, 'quantity' => 10,
        'average_cost' => 1000, 'current_price' => 1200, 'unrealized_gain_amount' => 2000, 'unrealized_gain_rate' => 20,
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->assertSeeInOrder(['同定未了社', '照合結果: 照合未実施'])
        ->assertSeeInOrder(['保有社', '照合結果: 保有中'])
        ->assertSeeInOrder(['登録済み社', '照合結果: ウォッチリスト登録済み'])
        ->assertSeeInOrder(['未登録社', '照合結果: 未登録'])
        ->assertDontSee('受け渡し履歴あり');
});

test('UC-016 市場からの調査候補発見・確認は星なしの既存行へ関連付けただけの候補を監視未登録の絞り込みに残す', function () {
    uc016FollowupBaseline();
    $ids = uc016FollowupFixture(['listings' => [['jp', '8888']]]);
    $item = WatchlistItem::create([
        'holding_id' => uc016FollowupHolding('8888')->id, 'source' => 'rakuten_favorites_csv',
        'is_starred' => false, 'last_seen_in_csv_at' => now(), 'registered_at' => now(),
    ]);
    $revisionId = DB::table('research_candidate_revisions')->where('research_candidate_id', $ids['candidate_id'])->value('id');
    DB::table('research_watchlist_handoffs')->insert([
        'research_candidate_id' => $ids['candidate_id'], 'research_candidate_revision_id' => $revisionId,
        'research_entity_listing_id' => DB::table('research_entity_listings')->value('id'),
        'holding_id' => $item->holding_id, 'watchlist_item_id' => $item->id, 'outcome' => 'linked_existing',
        'idempotency_key' => '00000000-0000-4000-8000-000000000001', 'created_at' => now(),
    ]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('unregisteredOnly', true);

    expect($component->viewData('candidates')->pluck('id')->all())->toBe([$ids['candidate_id']]);
});

test('UC-016 市場からの調査候補発見・確認は監視登録済み候補の元発表を統合すると要再確認にする', function () {
    $target = uc016FollowupFixture(['legal_name' => '甲社', 'listings' => [['jp', '1111']]]);
    $source = uc016FollowupFixture(['legal_name' => '乙社', 'listings' => [['jp', '2222']], 'status' => 'watchlisted']);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('mergeEvents', $source['event_id'], $target['event_id'])
        ->assertHasNoErrors();

    expect((bool) DB::table('research_candidates')->where('id', $source['candidate_id'])->value('needs_recheck'))->toBeTrue();
});

test('UC-016 市場からの調査候補発見・確認は統合済みの元発表IDへの新しい関連付けを拒否する', function () {
    $target = uc016FollowupFixture(['legal_name' => '甲社']);
    $source = uc016FollowupFixture(['legal_name' => '乙社', 'merged_into_event_id' => $target['event_id']]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', [
            'theme' => 'フィジカルAI', 'theme_relation' => '関係', 'event_title' => '',
            'discovery_url' => 'https://example.org/articles/new', 'source_title' => '別記事', 'publisher' => '別媒体',
            'checked_on' => '2026-10-04', 'summary' => '要約', 'legal_name' => '丙社',
            'original_url' => null, 'original_publisher' => null, 'announced_on' => null, 'entity_role' => null,
            'existing_event_id' => $source['event_id'], 'existing_entity_id' => null,
        ])
        ->call('saveCandidate')
        ->assertHasErrors(['form']);

    expect(DB::table('research_candidates')->count())->toBe(2);
});

test('UC-016 市場からの調査候補発見・確認は既存記録の候補を一操作で関連付け先に選べる', function () {
    $ids = uc016FollowupFixture(['discovery_url' => 'https://example.org/articles/shared', 'legal_name' => '甲社']);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form.discovery_url', 'https://example.org/articles/shared')
        ->set('form.legal_name', '甲社')
        ->assertSee('この元発表に関連付ける')
        ->assertSee('この法人に関連付ける')
        ->call('useExistingEvent', $ids['event_id'])
        ->call('useExistingEntity', $ids['entity_id'])
        ->assertSet('form.existing_event_id', $ids['event_id'])
        ->assertSet('form.existing_entity_id', $ids['entity_id']);
});

test('UC-016 市場からの調査候補発見・確認は発見経路URLと法人名の入力後に既存記録の照合を画面へ反映する', function () {
    // Livewire 4: wire:model.blur alone never sends a request, so the duplicate
    // warning would only appear after saving. Livewire::test()->set() cannot
    // detect this because it always re-renders.
    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->assertSeeHtml('wire:model.live.blur="form.discovery_url"')
        ->assertSeeHtml('wire:model.live.blur="form.legal_name"');
});
