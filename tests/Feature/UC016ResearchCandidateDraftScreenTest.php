<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\Holding;
use App\Models\HoldingMemo;
use App\Models\ResearchCandidate;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\Research\ResearchCandidateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';
require_once __DIR__.'/../Support/ResearchAutomationReviewFixtures.php';
require_once __DIR__.'/../Support/ResearchListingCatalogFixtures.php';

/**
 * UC-016「市場からの調査候補発見・確認」追加Red: R4/R7/R8, ADR-0033 D6/D7.
 * Proposed Gate 4 UI contract:
 * showDraft(id) loads #[Locked] draftReview (id/lock_version/document_version_id/
 * catalog_import_ids) and resets draftConfirmed=false. importDraft() uses that
 * server snapshot; optional draftNote and explicit draftExistingEventId /
 * draftExistingEntityId are human input, never trusted extracted provenance.
 * correctDraft(id, expectedVersion, overrides), changeDraftDisposition(id,
 * expectedVersion, disposition, reason), restoreDraft(id, expectedVersion)
 * enforce Policy and optimistic locking. Domain errors use the draft error key;
 * the confirmation checkbox alone uses draftConfirmed. No HTTP on these actions.
 * For an imported draft showDraft also locks imported_candidate_lock_version;
 * reconfirmDraft() records a human review of the current source version and a
 * candidate revision atomically, without replacing imported text/primary claims.
 * New rows are produced by public commands, never by test-only schema creation.
 */
beforeEach(function () {
    uc016AutomationConfig();
    uc016AutomationFakeHttp();
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
});

test('UC-016 市場からの調査候補発見・確認の企業候補画面は未認証者をログインへ戻す', function () {
    $this->get('/candidate-research')->assertRedirect('/login');
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_candidates', 0);
});

test('UC-016 市場からの調査候補発見・確認の画面閲覧は自動処理も外部アクセスも開始しない', function () {
    $this->actingAs(User::factory()->create())->get('/candidate-research')
        ->assertOk()->assertSee('調査候補')->assertSee('ウォッチリスト');
    Http::assertNothingSent();
    $this->assertDatabaseCount('research_source_checks', 0);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認は自動抽出有効時も既存の本人入力と要約を保持する', function () {
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->set('form', uc016DraftScreenManualForm())->call('saveCandidate')->assertHasNoErrors();

    $this->assertDatabaseHas('research_discovery_links', [
        'summary' => '本人のメモ。元発表と上場主体は未確認。',
        'checked_on' => '2026-10-19',
    ]);
    $this->assertDatabaseCount('research_candidates', 1);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は企業候補と資料のみと手動記録の入口を区別する', function () {
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('企業候補')->assertSee('資料のみ')->assertSee('手動で記録');
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認はFDAの企業とテーマと製品と出典を再入力なしで表示し確認は初期OFFにする', function () {
    uc016DraftScreenActions('showDraft');
    $draft = uc016DraftScreenPrepare();
    uc016AutomationFakeHttp();

    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->assertSee('Synthetic Medical Research Co.')->call('showDraft', $draft->id)
        ->assertHasNoErrors()->assertSet('draftConfirmed', false)
        ->assertSet('draftReview.id', $draft->id)
        ->assertSet('draftReview.lock_version', $draft->lock_version)
        ->assertSet('draftReview.document_version_id', $draft->current_document_version_id)
        ->assertSee('医療AI')->assertSee('Synthetic Imaging Assistant')->assertSee('K260001')
        ->assertSee('資料からの要点')->assertSee('本人の追記')->assertSee('上場先')
        ->assertSee('照合元欠測')->assertDontSee('未登録・未保有');
    expect($component->get('draftReview'))->toHaveKey('catalog_import_ids');
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は内容確認がOFFなら調査記録の取り込みを拒否する', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();
    uc016AutomationFakeHttp();

    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->call('importDraft')->assertHasErrors(['draftConfirmed']);
    $this->assertDatabaseCount('research_events', 0);
    $this->assertDatabaseCount('research_entities', 0);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('research_discovery_links', 0);
    $this->assertDatabaseHas('research_candidate_drafts', ['id' => $draft->id, 'imported_candidate_id' => null]);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認はクライアントによる確認済み資料版の差し替えを拒否する', function () {
    uc016DraftScreenActions('showDraft');
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->assertHasNoErrors();
    $before = (array) DB::table('research_candidate_drafts')->find($draft->id);
    uc016AutomationFakeHttp();

    expect(fn () => $component->set('draftReview.document_version_id', $draft->current_document_version_id + 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect((array) DB::table('research_candidate_drafts')->find($draft->id))->toBe($before);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('research_discovery_links', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は自動文と本人要約を分離し本人が確認した実日付で原資料版ごと取り込む', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 04:00:00', 'UTC'));
    $user = User::factory()->create();
    uc016AutomationFakeHttp();

    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true)->call('importDraft')->assertHasNoErrors();

    $link = DB::table('research_discovery_links')->sole();
    expect($link->input_origin)->toBe('automatic')->and($link->summary)->toBe('')
        ->and($link->generated_summary)->toContain('Synthetic Imaging Assistant')
        ->and($link->generated_summary)->toContain('K260001')
        ->and($link->source_document_version_id)->toBe($draft->current_document_version_id)
        ->and($link->checked_on)->toBe('2026-10-20')->and($link->posted_on)->toBeNull();
    $this->assertDatabaseHas('research_candidate_drafts', [
        'id' => $draft->id, 'disposition' => 'imported', 'imported_link_id' => $link->id,
        'imported_version_id' => $draft->current_document_version_id,
        'reviewed_version_id' => $draft->current_document_version_id,
        'reviewed_by' => $user->id, 'reviewed_on' => '2026-10-20',
    ]);
    $this->assertDatabaseHas('research_events', ['verification_status' => 'unverified', 'verified_on' => null, 'announced_on' => null]);
    $this->assertDatabaseHas('research_entities', ['identification_status' => 'unidentified']);
    expect(DB::table('research_entity_listings')->whereNotNull('confirmed_on')->count())->toBe(0)
        ->and(DB::table('research_claims')->where('status', 'verified')->count())->toBe(0)
        ->and(DB::table('research_candidates')->sole()->counter_evidence_checked_on)->toBeNull();
    $this->assertDatabaseCount('research_candidate_revisions', 1);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は本人追記を資料由来の要点へ混ぜない', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();

    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftNote', '売上の寄与はまだ調べていない。')
        ->set('draftConfirmed', true)->call('importDraft')->assertHasNoErrors();

    $link = DB::table('research_discovery_links')->sole();
    expect($link->summary)->toBe('売上の寄与はまだ調べていない。')
        ->and($link->generated_summary)->not->toContain('売上の寄与はまだ調べていない。');
});

test('UC-016 市場からの調査候補発見・確認は同じ確認画面の二重取り込みを同じ調査記録と発見経路へ収束させる', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true)->call('importDraft')->assertHasNoErrors();
    $first = DB::table('research_candidate_drafts')->find($draft->id);
    $component->call('importDraft')->assertHasNoErrors();

    $second = DB::table('research_candidate_drafts')->find($draft->id);
    expect($second->imported_candidate_id)->toBe($first->imported_candidate_id)
        ->and($second->imported_link_id)->toBe($first->imported_link_id);
    foreach (['research_events', 'research_entities', 'research_candidates', 'research_discovery_links', 'research_candidate_revisions'] as $table) {
        $this->assertDatabaseCount($table, 1);
    }
    expect(DB::table('research_draft_revisions')->where('draft_id', $draft->id)->where('change_type', 'imported')->count())->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は本人選択した既存の元発表と法人と候補へ関連付けUC-012の星とフォルダとメモを保持する', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();
    $user = User::factory()->create();
    $component = Livewire::actingAs($user)->test(CandidateResearch::class)
        ->set('form', uc016DraftScreenManualForm())->call('saveCandidate')->assertHasNoErrors();
    $candidate = DB::table('research_candidates')->sole();
    $holding = Holding::create([
        'symbol_code' => 'SYNM', 'market' => 'us', 'instrument_type' => 'stock',
        'symbol_name' => 'Synthetic Medical Research Co.', 'first_detected_at' => now(),
    ]);
    $watch = WatchlistItem::create([
        'holding_id' => $holding->id, 'folder_name' => '本人の医療テーマ', 'exchange_label' => 'NASDAQ',
        'source' => 'rakuten_favorites_csv', 'is_starred' => false, 'registered_at' => now(),
    ]);
    $memo = HoldingMemo::create(['holding_id' => $holding->id, 'body' => '本人の既存メモ', 'recorded_at' => now()]);
    $beforeWatch = $watch->fresh()->getRawOriginal();
    $beforeMemo = $memo->fresh()->getRawOriginal();

    $component->call('showDraft', $draft->id)
        ->set('draftExistingEventId', $candidate->research_event_id)
        ->set('draftExistingEntityId', $candidate->research_entity_id)
        ->set('draftConfirmed', true)->call('importDraft')->assertHasNoErrors();

    $this->assertDatabaseCount('research_events', 1);
    $this->assertDatabaseCount('research_entities', 1);
    $this->assertDatabaseCount('research_candidates', 1);
    $this->assertDatabaseCount('research_discovery_links', 2);
    $this->assertDatabaseHas('research_candidate_drafts', ['id' => $draft->id, 'imported_candidate_id' => $candidate->id]);
    expect($watch->refresh()->getRawOriginal())->toBe($beforeWatch)
        ->and($memo->refresh()->getRawOriginal())->toBe($beforeMemo);
    $this->assertDatabaseHas('research_discovery_links', ['summary' => '本人のメモ。元発表と上場主体は未確認。']);
});

test('UC-016 市場からの調査候補発見・確認は別画面で訂正された古い下書き版の取り込みを一切保存せず拒否する', function () {
    uc016DraftScreenActions('showDraft', 'importDraft', 'correctDraft');
    $draft = uc016DraftScreenPrepare();
    $user = User::factory()->create();
    $old = Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true);
    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('correctDraft', $draft->id, $draft->lock_version, ['roles' => ['掲載法人・本人訂正']])->assertHasNoErrors();

    $old->call('importDraft')->assertHasErrors(['draft']);
    foreach (['research_events', 'research_entities', 'research_candidates', 'research_discovery_links'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseHas('research_candidate_drafts', ['id' => $draft->id, 'imported_candidate_id' => null]);
});

test('UC-016 市場からの調査候補発見・確認は元資料訂正後の古い確認画面から取り込まない', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 04:00:00', 'UTC'));
    uc016DraftScreenResponse(['Device' => 'Corrected Imaging Assistant']);
    expect(uc016AutomationCheckAndDiscover(true))->toBe(0);
    expect(DB::table('research_candidate_drafts')->find($draft->id)->current_document_version_id)
        ->not->toBe($draft->current_document_version_id);
    uc016AutomationFakeHttp();

    $component->call('importDraft')->assertHasErrors(['draft']);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('research_discovery_links', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は公式上場マスタ公開版が変われば古い確認画面からの取り込みを全て保存せず拒否する', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq'],
    ]));
    expect(uc016AutomationDiscover())->toBe(0);
    $draft = DB::table('research_candidate_drafts')->sole();
    $oldCatalogId = DB::table('research_listing_catalog_imports')->where('status', 'published')->sole()->id;
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true);
    expect($component->get('draftReview.catalog_import_ids'))->toContain($oldCatalogId);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 05:00:00', 'UTC'));
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNX', 'NYSE'],
    ]));
    expect(uc016AutomationDiscover())->toBe(0);
    $newCatalogId = DB::table('research_listing_catalog_imports')->where('status', 'published')->latest('id')->first()->id;
    expect($newCatalogId)->not->toBe($oldCatalogId);
    $beforeDraft = (array) DB::table('research_candidate_drafts')->find($draft->id);
    $beforeHistory = DB::table('research_draft_revisions')->count();
    uc016AutomationFakeHttp();

    $component->call('importDraft')->assertHasErrors(['draft']);

    foreach (['research_events', 'research_entities', 'research_candidates', 'research_discovery_links', 'research_candidate_revisions'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect((array) DB::table('research_candidate_drafts')->find($draft->id))->toBe($beforeDraft);
    $this->assertDatabaseCount('research_draft_revisions', $beforeHistory);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は監視可能な取り込み済み候補でも資料訂正後は拒否し本人の再確認版と候補版を一体更新すると監視へ渡せる', function () {
    uc016DraftScreenActions('showDraft', 'importDraft', 'reconfirmDraft');
    $actor = User::factory()->create();
    ['draft' => $draft, 'candidate' => $candidate, 'component' => $component] = uc016DraftScreenReadyImport($actor);
    $component->call('prepareHandoff', $candidate->id)->assertHasNoErrors()
        ->assertSet('handoff.error', null);
    $oldLink = (array) DB::table('research_discovery_links')->find($draft->imported_link_id);
    $oldClaim = (array) DB::table('research_claims')->where('research_candidate_id', $candidate->id)->sole();
    $oldBody = $candidate->only(['theme', 'theme_relation', 'entity_role', 'evidence_stage', 'counter_evidence', 'status']);
    $firstImportedVersion = $draft->imported_version_id;
    $this->travelTo(CarbonImmutable::parse('2026-10-21 04:00:00', 'UTC'));
    uc016DraftScreenResponse(['Device' => 'Corrected Imaging Assistant']);
    expect(uc016AutomationCheckAndDiscover(true))->toBe(0);
    $current = DB::table('research_candidate_drafts')->find($draft->id);
    expect($current->current_document_version_id)->not->toBe($firstImportedVersion)
        ->and($current->reviewed_version_id)->toBe($firstImportedVersion);
    expect(ResearchCandidate::findOrFail($candidate->id)->only(array_keys($oldBody)))->toBe($oldBody)
        ->and((array) DB::table('research_discovery_links')->find($draft->imported_link_id))->toBe($oldLink)
        ->and((array) DB::table('research_claims')->find($oldClaim['id']))->toBe($oldClaim);
    $component->call('prepareHandoff', $candidate->id)->assertHasErrors(['handoff']);
    $this->assertDatabaseCount('research_watchlist_handoffs', 0);

    // Only this explicit existing human edit changes the fact/confirmation text.
    $service = app(ResearchCandidateService::class);
    $candidate = $service->update($candidate->id, ResearchCandidate::findOrFail($candidate->id)->lock_version, [
        'verified_on' => '2026-10-21', 'counter_evidence_checked_on' => '2026-10-21',
        'claim' => 'Corrected Imaging Assistantが対象一覧に掲載されたことを再確認した',
        'claim_status' => 'verified', 'claim_primary' => true, 'claim_checked_on' => '2026-10-21',
    ], $actor);
    $beforeReconfirmation = $candidate->lock_version;
    $beforeDraftVersion = DB::table('research_candidate_drafts')->find($draft->id)->lock_version;
    $humanClaim = (array) DB::table('research_claims')->find($oldClaim['id']);
    $review = Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->assertSet('draftConfirmed', false)
        ->assertSet('draftReview.imported_candidate_lock_version', $beforeReconfirmation)
        ->set('draftConfirmed', true);
    uc016AutomationFakeHttp();

    $review->call('reconfirmDraft')->assertHasNoErrors();

    $afterDraft = DB::table('research_candidate_drafts')->find($draft->id);
    $afterCandidate = ResearchCandidate::findOrFail($candidate->id);
    expect($afterDraft->reviewed_version_id)->toBe($current->current_document_version_id)
        ->and($afterDraft->reviewed_on)->toBe('2026-10-21')->and($afterDraft->reviewed_by)->toBe($actor->id)
        ->and($afterDraft->imported_version_id)->toBe($firstImportedVersion)
        ->and($afterDraft->lock_version)->toBeGreaterThan($beforeDraftVersion)
        ->and($afterCandidate->lock_version)->toBe($beforeReconfirmation + 1)
        ->and((array) DB::table('research_discovery_links')->find($draft->imported_link_id))->toBe($oldLink)
        ->and((array) DB::table('research_claims')->find($oldClaim['id']))->toBe($humanClaim);
    expect(DB::table('research_candidate_revisions')->where('research_candidate_id', $candidate->id)
        ->where('lock_version', $afterCandidate->lock_version)->count())->toBe(1);
    $review->call('prepareHandoff', $candidate->id)->assertHasNoErrors()->assertSet('handoff.error', null)
        ->call('confirmHandoff', '訂正差分と一次資料と上場主体を再確認し監視を選んだ')->assertHasNoErrors();
    $this->assertDatabaseCount('research_watchlist_handoffs', 1);
    $this->assertDatabaseCount('watchlist_items', 2);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は資料差分の再確認操作でも未確認に戻した一次主張を自動で確認済みにしない', function () {
    uc016DraftScreenActions('showDraft', 'importDraft', 'reconfirmDraft');
    $actor = User::factory()->create();
    ['draft' => $draft, 'candidate' => $candidate] = uc016DraftScreenReadyImport($actor);
    expect(app(ResearchCandidateService::class)->prepareHandoff($candidate->id, $actor)['error'])->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 04:00:00', 'UTC'));
    uc016DraftScreenResponse(['Device' => 'Corrected Imaging Assistant']);
    expect(uc016AutomationCheckAndDiscover(true))->toBe(0);
    app(ResearchCandidateService::class)->update($candidate->id, ResearchCandidate::findOrFail($candidate->id)->lock_version, [
        'claim' => '訂正後の機器との対応は未確認', 'claim_status' => 'unverified', 'claim_checked_on' => null,
    ], $actor);
    $beforeClaim = (array) DB::table('research_claims')->where('research_candidate_id', $candidate->id)->sole();
    uc016AutomationFakeHttp();

    $component = Livewire::actingAs($actor)->test(CandidateResearch::class)->call('showDraft', $draft->id)
        ->set('draftConfirmed', true)->call('reconfirmDraft');

    expect((array) DB::table('research_claims')->find($beforeClaim['id']))->toBe($beforeClaim);
    $component->call('prepareHandoff', $candidate->id)->assertHasErrors(['handoff']);
    $this->assertDatabaseCount('research_watchlist_handoffs', 0);
    $this->assertDatabaseCount('watchlist_items', 1);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は本人訂正と変更前後の履歴を保持し資料更新で上書きしない', function () {
    uc016DraftScreenActions('correctDraft', 'showDraft');
    $draft = uc016DraftScreenPrepare();
    $user = User::factory()->create();
    $overrides = ['roles' => ['本人が確認した研究協力先']];
    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('correctDraft', $draft->id, $draft->lock_version, $overrides)->assertHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 04:00:00', 'UTC'));
    uc016DraftScreenResponse(['Device' => 'Updated Imaging Assistant']);
    expect(uc016AutomationCheckAndDiscover(true))->toBe(0);

    $after = DB::table('research_candidate_drafts')->find($draft->id);
    expect(json_decode($after->human_overrides, true, flags: JSON_THROW_ON_ERROR))->toBe($overrides);
    $revision = DB::table('research_draft_revisions')->where('draft_id', $draft->id)->where('change_type', 'corrected')->sole();
    expect($revision->actor_id)->toBe($user->id)->and($revision->payload)->toContain('本人が確認した研究協力先');
    Livewire::actingAs($user)->test(CandidateResearch::class)->call('showDraft', $draft->id)
        ->assertSee('本人が確認した研究協力先')->assertSee('Updated Imaging Assistant');
});

test('UC-016 市場からの調査候補発見・確認は保留と見送りを再取得で復活させず本人の理由と版を保持する', function (string $disposition) {
    uc016DraftScreenActions('changeDraftDisposition');
    $draft = uc016DraftScreenPrepare();
    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('changeDraftDisposition', $draft->id, $draft->lock_version, $disposition, '上場主体の根拠が不足している')->assertHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 04:00:00', 'UTC'));
    uc016DraftScreenResponse(['Device' => 'Updated Imaging Assistant']);
    expect(uc016AutomationCheckAndDiscover(true))->toBe(0);

    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $this->assertDatabaseHas('research_candidate_drafts', [
        'id' => $draft->id, 'disposition' => $disposition, 'reason' => '上場主体の根拠が不足している',
    ]);
    $this->assertDatabaseCount('research_candidates', 0);
})->with(['保留' => 'on_hold', '見送り' => 'rejected']);

test('UC-016 市場からの調査候補発見・確認は本人操作で見送りを復元し元資料と判断履歴を削除しない', function () {
    uc016DraftScreenActions('changeDraftDisposition', 'restoreDraft');
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('changeDraftDisposition', $draft->id, $draft->lock_version, 'rejected', '今回は対象外')->assertHasNoErrors();
    $rejected = DB::table('research_candidate_drafts')->find($draft->id);
    $historyCount = DB::table('research_draft_revisions')->where('draft_id', $draft->id)->count();
    $component->call('restoreDraft', $draft->id, $rejected->lock_version)->assertHasNoErrors();

    $restored = DB::table('research_candidate_drafts')->find($draft->id);
    expect($restored->disposition)->toBe('unreviewed')->and($restored->document_id)->toBe($draft->document_id)
        ->and($restored->lock_version)->toBeGreaterThan($rejected->lock_version);
    expect(DB::table('research_draft_revisions')->where('draft_id', $draft->id)->count())->toBe($historyCount + 1);
    expect(DB::table('research_draft_revisions')->where('draft_id', $draft->id)->where('change_type', 'disposition_changed')->sole()->payload)
        ->toContain('今回は対象外');
});

test('UC-016 市場からの調査候補発見・確認は資料確認だけで既存UC-012の監視登録条件を満たしたことにしない', function () {
    uc016DraftScreenActions('showDraft', 'importDraft');
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true)->call('importDraft')->assertHasNoErrors();
    $candidateId = DB::table('research_candidate_drafts')->find($draft->id)->imported_candidate_id;
    $component->call('prepareHandoff', $candidateId)->assertHasErrors(['handoff']);

    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    $this->assertDatabaseCount('research_watchlist_handoffs', 0);
    $this->assertDatabaseHas('research_candidates', ['id' => $candidateId, 'status' => 'investigating']);
});

test('UC-016 市場からの調査候補発見・確認は古い照合元と取得失敗を表示し前回の上場候補を確認済みにしない', function (string $state, string $warning) {
    uc016DraftScreenActions('showDraft');
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq'],
    ]));
    expect(uc016AutomationDiscover())->toBe(0);
    $draft = DB::table('research_candidate_drafts')->sole();
    if ($state === 'stale') {
        config(['research_automation.catalogs.sec_exchange.enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-27 04:00:00', 'UTC'));
        expect(uc016AutomationDiscover())->toBe(0);
    } else {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 05:00:00', 'UTC'));
        uc016CatalogResponses('sec_exchange', 'PRIVATE_UPSTREAM_ERROR_MUST_NOT_BE_DISPLAYED', 503);
        uc016AutomationDiscover();
    }
    uc016AutomationFakeHttp();

    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->assertSee($warning)->assertSee('上場先候補')
        ->assertDontSee('PRIVATE_UPSTREAM_ERROR_MUST_NOT_BE_DISPLAYED')->assertDontSee('未登録・未保有');
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
})->with([
    'stale SEC snapshot' => ['stale', '照合元が古い'],
    'failed SEC refresh' => ['failed', '照合元の取得失敗'],
]);

test('UC-016 市場からの調査候補発見・確認は権限喪失後の下書き操作を拒否し履歴や調査記録を変えない', function (string $action) {
    uc016DraftScreenActions('showDraft', $action);
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true);
    $before = (array) DB::table('research_candidate_drafts')->find($draft->id);
    $historyCount = DB::table('research_draft_revisions')->count();
    Gate::before(fn ($user, $ability, $arguments) => false);
    uc016AutomationFakeHttp();
    $args = match ($action) {
        'showDraft' => [$draft->id],
        'importDraft', 'reconfirmDraft' => [],
        'correctDraft' => [$draft->id, $draft->lock_version, ['roles' => ['無権限の訂正']]],
        'changeDraftDisposition' => [$draft->id, $draft->lock_version, 'rejected', '無権限の見送り'],
        'restoreDraft' => [$draft->id, $draft->lock_version],
    };

    $component->call($action, ...$args)->assertForbidden();
    expect((array) DB::table('research_candidate_drafts')->find($draft->id))->toBe($before);
    $this->assertDatabaseCount('research_draft_revisions', $historyCount);
    $this->assertDatabaseCount('research_candidates', 0);
    Http::assertNothingSent();
})->with(['showDraft', 'importDraft', 'correctDraft', 'changeDraftDisposition', 'restoreDraft', 'reconfirmDraft']);
