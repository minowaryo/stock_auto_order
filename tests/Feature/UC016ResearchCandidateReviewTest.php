<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\ResearchCandidate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * UC-016「市場からの調査候補発見・確認」: 出来事・照合・履歴の Red テスト。
 *
 * Gate 4 で確認する Livewire 契約:
 * - CandidateResearch::saveCandidate() は form.existing_event_id と
 *   form.existing_entity_id が指定された場合だけ既存記録に関連付ける。
 *   タイトル類似や URL の見た目だけで元発表を自動統合しない。
 * - 画面の viewData('stats') は original_events / verified_listed_entities /
 *   this_week_new_discoveries / past_announcements を返す。
 * - editCandidate(id) で editForm と lock_version を読み、saveChanges() は
 *   競合時に保存せず最新版の再確認を促す。
 * - requestArchive(id) → confirmArchive() は確認操作を伴うアーカイブ。
 *   restoreCandidate(id) で復元する。prepareHandoff(id) は前提不足を拒否する。
 * - mergeEvents(sourceEventId, targetEventId) / unmergeEvent(sourceEventId) は
 *   元発表の関連付け・解除。beginStatusChange(id, status) → saveStatus() は
 *   statusReason を必須にして保留・見送りを保存する。
 * - themeFilter / statusFilter と Livewire の nextPage() で20件ずつ閲覧する。
 * - 監査 action 名は research_event.merged / .unmerged、
 *   research_candidate.archived / .restored を仮契約とする。
 * これらの操作名は仕様をテストするための仮契約で、Gate 4 レビュー対象。
 */
function uc016ReviewForm(array $overrides = []): array
{
    return array_replace([
        'theme' => 'フィジカルAI',
        'theme_relation' => '搬送ロボットの供給網に関係する',
        'event_title' => 'ロボット量産計画',
        'discovery_url' => 'https://example.org/articles/robot-a',
        'source_title' => '紹介記事A',
        'publisher' => '紹介元',
        'checked_on' => '2026-10-04',
        'summary' => '紹介元の主張。元資料との一致は未確認。',
        'legal_name' => '例示ロボット株式会社',
        'original_url' => null,
        'original_publisher' => null,
        'announced_on' => null,
        'entity_role' => null,
        'existing_event_id' => null,
        'existing_entity_id' => null,
    ], $overrides);
}

/** @return array{event_id:int, entity_id:int, candidate_id:int} */
function uc016ReviewFixture(array $overrides = []): array
{
    $now = $overrides['created_at'] ?? now();
    $eventId = DB::table('research_events')->insertGetId([
        'title' => $overrides['event_title'] ?? 'ロボット量産計画',
        'original_url' => $overrides['original_url'] ?? null,
        'original_publisher' => $overrides['original_publisher'] ?? null,
        'announced_on' => $overrides['announced_on'] ?? null,
        'verification_status' => $overrides['verification_status'] ?? 'unverified',
        'verified_on' => $overrides['verified_on'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $linkUrl = $overrides['discovery_url'] ?? 'https://example.org/articles/robot-a';
    DB::table('research_discovery_links')->insert([
        'research_event_id' => $eventId,
        'route_type' => 'manual',
        'url' => $linkUrl,
        'url_hash' => hash('sha256', $linkUrl),
        'source_title' => '紹介記事A',
        'publisher' => '紹介元',
        'checked_on' => '2026-10-04',
        'summary' => '紹介元の主張。元資料との一致は未確認。',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $entityId = DB::table('research_entities')->insertGetId([
        'legal_name' => $overrides['legal_name'] ?? '例示ロボット株式会社',
        'identification_status' => $overrides['identification_status'] ?? 'unidentified',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $candidateId = DB::table('research_candidates')->insertGetId([
        'research_event_id' => $eventId,
        'research_entity_id' => $entityId,
        'theme' => $overrides['theme'] ?? 'フィジカルAI',
        'theme_relation' => $overrides['theme_relation'] ?? '搬送ロボットの供給網に関係する',
        'entity_role' => $overrides['entity_role'] ?? null,
        'evidence_stage' => $overrides['evidence_stage'] ?? 'unknown',
        'counter_evidence' => $overrides['counter_evidence'] ?? null,
        'counter_evidence_checked_on' => $overrides['counter_evidence_checked_on'] ?? null,
        'status' => $overrides['status'] ?? 'investigating',
        'status_reason' => $overrides['status_reason'] ?? null,
        'lock_version' => 1,
        'archived_at' => $overrides['archived_at'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ($overrides['listings'] ?? [] as [$market, $code]) {
        DB::table('research_entity_listings')->insert([
            'research_entity_id' => $entityId,
            'market' => $market,
            'symbol_code' => $code,
            'listed_entity_name' => $overrides['legal_name'] ?? '例示ロボット株式会社',
            'source_url' => 'https://example.org/investors/listing',
            'confirmed_on' => '2026-10-04',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    if (isset($overrides['verified_claim'])) {
        DB::table('research_claims')->insert([
            'research_candidate_id' => $candidateId,
            'claim' => $overrides['verified_claim'],
            'evidence_url' => $overrides['original_url'],
            'status' => 'verified',
            'is_primary_source' => true,
            'checked_on' => '2026-10-04',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('research_candidate_revisions')->insert([
        'research_candidate_id' => $candidateId,
        'lock_version' => 1,
        'change_type' => 'created',
        'payload' => uc016ReviewSnapshot($candidateId),
        'created_at' => $now,
    ]);

    return ['event_id' => $eventId, 'entity_id' => $entityId, 'candidate_id' => $candidateId];
}

function uc016ReviewSnapshot(int $candidateId): string
{
    $candidate = DB::table('research_candidates')->where('id', $candidateId)->sole();

    return json_encode([
        'candidate' => (array) $candidate,
        'event' => (array) DB::table('research_events')->where('id', $candidate->research_event_id)->sole(),
        'discovery_links' => DB::table('research_discovery_links')->where('research_event_id', $candidate->research_event_id)->get()->map(fn ($row) => (array) $row)->all(),
        'entity' => (array) DB::table('research_entities')->where('id', $candidate->research_entity_id)->sole(),
        'entity_listings' => DB::table('research_entity_listings')->where('research_entity_id', $candidate->research_entity_id)->get()->map(fn ($row) => (array) $row)->all(),
        'claims' => DB::table('research_claims')->where('research_candidate_id', $candidateId)->get()->map(fn ($row) => (array) $row)->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

test('UC-016 市場からの調査候補発見・確認は同じ元発表の転載を別の発見経路として残す', function () {
    $existing = uc016ReviewFixture([
        'original_url' => 'https://example.org/press/robot-a',
        'original_publisher' => '発表企業',
        'announced_on' => '2026-09-30',
    ]);
    $input = uc016ReviewForm([
        'discovery_url' => 'https://example.net/videos/robot-a',
        'source_title' => '同じ発表を紹介する動画',
        'publisher' => '動画の発行者',
        'summary' => '同じ元発表を紹介している。独立した裏付けではない。',
        'existing_event_id' => $existing['event_id'],
        'existing_entity_id' => $existing['entity_id'],
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', $input)
        ->call('saveCandidate')
        ->assertHasNoErrors();

    expect(DB::table('research_events')->count())->toBe(1)
        ->and(DB::table('research_entities')->count())->toBe(1)
        ->and(DB::table('research_candidates')->count())->toBe(1)
        ->and(DB::table('research_discovery_links')->count())->toBe(2)
        ->and(DB::table('research_discovery_links')->where('research_event_id', $existing['event_id'])->count())->toBe(2);
});

test('UC-016 市場からの調査候補発見・確認は似た題名でも異なる元発表を分ける', function () {
    $existing = uc016ReviewFixture([
        'original_url' => 'https://example.org/press/robot-a',
        'announced_on' => '2026-09-30',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', uc016ReviewForm([
            'event_title' => 'ロボット量産計画',
            'discovery_url' => 'https://example.org/articles/robot-b',
            'source_title' => '別の発表を紹介する記事',
            'original_url' => 'https://example.org/press/robot-b',
            'announced_on' => '2026-10-02',
            'existing_event_id' => null,
            'existing_entity_id' => $existing['entity_id'],
        ]))
        ->call('saveCandidate')
        ->assertHasNoErrors();

    expect(DB::table('research_events')->count())->toBe(2)
        ->and(DB::table('research_entities')->count())->toBe(1)
        ->and(DB::table('research_candidates')->count())->toBe(2)
        ->and(DB::table('research_discovery_links')->count())->toBe(2);
});

test('UC-016 市場からの調査候補発見・確認は同定未了と照合欠測を未登録と断定しない', function () {
    uc016ReviewFixture();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->assertSee('同定未了')
        ->assertSee('照合未実施');

    expect(DB::table('research_entity_listings')->count())->toBe(0)
        ->and(DB::table('research_watchlist_handoffs')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は上場主体が同定済みでもCSVと保有の照合がなければ監視登録を保留する', function () {
    $existing = uc016ReviewFixture([
        'original_url' => 'https://example.org/press/robot',
        'original_publisher' => '発表企業',
        'announced_on' => '2026-09-30',
        'verification_status' => 'verified',
        'verified_on' => '2026-10-04',
        'identification_status' => 'identified',
        'entity_role' => '供給者',
        'counter_evidence' => '売上の寄与は未確認',
        'counter_evidence_checked_on' => '2026-10-04',
        'listings' => [['jp', '1234']],
        'verified_claim' => '発表企業が製品を公表した',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $existing['candidate_id'])
        ->assertSee('照合未実施');

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(0)
        ->and(DB::table('holdings')->count())->toBe(0)
        ->and(DB::table('watchlist_items')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は古い元発表を今週の新規発見から分ける', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
    uc016ReviewFixture(['announced_on' => '2025-10-01']);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->viewData('stats');

    expect($stats['original_events'])->toBe(1)
        ->and($stats['this_week_new_discoveries'])->toBe(0)
        ->and($stats['past_announcements'])->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は同一法人の複数上場を確認済み上場企業一社と数える', function () {
    uc016ReviewFixture([
        'identification_status' => 'identified',
        'listings' => [['jp', '1234'], ['us', 'EXRB']],
    ]);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->viewData('stats');

    expect($stats['original_events'])->toBe(1)
        ->and($stats['verified_listed_entities'])->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は訂正前後を別の版として保存する', function () {
    $existing = uc016ReviewFixture();
    $firstRevision = DB::table('research_candidate_revisions')
        ->where('research_candidate_id', $existing['candidate_id'])
        ->sole();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('editCandidate', $existing['candidate_id'])
        ->set('editForm.theme_relation', '一次資料を読んだ結果、供給関係は未確認')
        ->call('saveChanges')
        ->assertHasNoErrors();

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('theme_relation'))
        ->toBe('一次資料を読んだ結果、供給関係は未確認');
    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('lock_version'))
        ->toBe(2);

    $revisions = DB::table('research_candidate_revisions')
        ->where('research_candidate_id', $existing['candidate_id'])
        ->orderBy('lock_version')->get();
    expect($revisions)->toHaveCount(2)
        ->and($revisions[0]->payload)->toBe($firstRevision->payload)
        ->and($revisions[0]->lock_version)->toBe(1)
        ->and($revisions[1]->lock_version)->toBe(2)
        ->and($revisions[1]->change_type)->toBe('updated');
});

test('UC-016 市場からの調査候補発見・確認は古い版による訂正を拒否して最新版を示す', function () {
    $existing = uc016ReviewFixture();
    $user = User::factory()->create();
    $firstEditor = Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('editCandidate', $existing['candidate_id']);
    $staleEditor = Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('editCandidate', $existing['candidate_id']);

    $firstEditor->set('editForm.theme_relation', '一次資料で供給者と確認')
        ->call('saveChanges')
        ->assertHasNoErrors();
    $staleEditor->set('editForm.theme_relation', '古い画面からの上書き')
        ->call('saveChanges')
        ->assertSee('最新版');

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('theme_relation'))
        ->toBe('一次資料で供給者と確認')
        ->and(DB::table('research_candidate_revisions')->where('research_candidate_id', $existing['candidate_id'])->count())
        ->toBe(2);
});

test('UC-016 市場からの調査候補発見・確認は確認後のアーカイブから復元でき元資料と法人を消さない', function () {
    $existing = uc016ReviewFixture();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('requestArchive', $existing['candidate_id'])
        ->call('confirmArchive')
        ->assertHasNoErrors();

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('archived_at'))
        ->not->toBeNull();
    $this->actingAs($user)->get('/candidate-research')->assertDontSee('例示ロボット株式会社');
    expect(DB::table('research_events')->where('id', $existing['event_id'])->exists())->toBeTrue()
        ->and(DB::table('research_entities')->where('id', $existing['entity_id'])->exists())->toBeTrue();

    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('restoreCandidate', $existing['candidate_id'])
        ->assertHasNoErrors();

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('archived_at'))
        ->toBeNull();
    $this->actingAs($user)->get('/candidate-research')->assertSee('例示ロボット株式会社');
    expect(DB::table('research_candidate_revisions')->where('research_candidate_id', $existing['candidate_id'])->count())
        ->toBe(3);
});

test('UC-016 市場からの調査候補発見・確認は未認証者の調査記録の閲覧と変更を拒否する', function () {
    $existing = uc016ReviewFixture();

    $this->get('/candidate-research')->assertRedirect('/login');
    expect(Gate::forUser(null)->denies('update', ResearchCandidate::findOrFail($existing['candidate_id'])))
        ->toBeTrue();

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('lock_version'))
        ->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は元発表の統合と誤統合解除で記録と版を残し集計を直す', function () {
    $target = uc016ReviewFixture([
        'event_title' => '元発表A',
        'discovery_url' => 'https://example.org/articles/original-a',
        'legal_name' => '甲社',
    ]);
    $source = uc016ReviewFixture([
        'event_title' => '元発表Aの転載',
        'discovery_url' => 'https://example.org/articles/reprint-a',
        'legal_name' => '乙社',
    ]);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(CandidateResearch::class);
    expect($component->viewData('stats')['original_events'])->toBe(2);

    $component->call('mergeEvents', $source['event_id'], $target['event_id'])
        ->assertHasNoErrors();

    expect(DB::table('research_events')->where('id', $source['event_id'])->value('merged_into_event_id'))
        ->toBe($target['event_id'])
        ->and(DB::table('research_events')->count())->toBe(2)
        ->and(DB::table('research_candidates')->count())->toBe(2)
        ->and(DB::table('research_discovery_links')->count())->toBe(2)
        ->and(DB::table('research_candidates')->where('id', $source['candidate_id'])->value('lock_version'))->toBe(2)
        ->and(DB::table('research_candidate_revisions')->where('research_candidate_id', $source['candidate_id'])->orderByDesc('lock_version')->value('change_type'))->toBe('merged')
        ->and($component->viewData('stats')['original_events'])->toBe(1);

    $component->call('unmergeEvent', $source['event_id'])
        ->assertHasNoErrors();

    expect(DB::table('research_events')->where('id', $source['event_id'])->value('merged_into_event_id'))
        ->toBeNull()
        ->and(DB::table('research_events')->count())->toBe(2)
        ->and(DB::table('research_candidates')->where('id', $source['candidate_id'])->value('lock_version'))->toBe(3)
        ->and(DB::table('research_candidate_revisions')->where('research_candidate_id', $source['candidate_id'])->orderByDesc('lock_version')->value('change_type'))->toBe('unmerged')
        ->and(DB::table('research_candidate_revisions')->where('research_candidate_id', $source['candidate_id'])->count())->toBe(3)
        ->and($component->viewData('stats')['original_events'])->toBe(2);
});

test('UC-016 市場からの調査候補発見・確認は保留と見送りに理由がなければ保存しない', function () {
    $existing = uc016ReviewFixture();
    $user = User::factory()->create();

    foreach (['on_hold', 'rejected'] as $status) {
        Livewire::actingAs($user)->test(CandidateResearch::class)
            ->call('beginStatusChange', $existing['candidate_id'], $status)
            ->set('statusReason', '')
            ->call('saveStatus')
            ->assertHasErrors(['statusReason' => 'required']);
    }

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('status'))
        ->toBe('investigating')
        ->and(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('lock_version'))->toBe(1)
        ->and(DB::table('research_candidate_revisions')->where('research_candidate_id', $existing['candidate_id'])->count())->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は保留と見送りの判断理由と状態変更履歴を残す', function () {
    $existing = uc016ReviewFixture();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('beginStatusChange', $existing['candidate_id'], 'on_hold')
        ->set('statusReason', '上場主体の公式資料を確認待ち')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('status'))
        ->toBe('on_hold')
        ->and(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('status_reason'))->toBe('上場主体の公式資料を確認待ち');

    Livewire::actingAs($user)->test(CandidateResearch::class)
        ->call('beginStatusChange', $existing['candidate_id'], 'rejected')
        ->set('statusReason', '一次資料で事業との関係が否定された')
        ->call('saveStatus')
        ->assertHasNoErrors();

    $revisions = DB::table('research_candidate_revisions')
        ->where('research_candidate_id', $existing['candidate_id'])
        ->orderBy('lock_version')->get();
    expect(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('status'))
        ->toBe('rejected')
        ->and(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('status_reason'))->toBe('一次資料で事業との関係が否定された')
        ->and(DB::table('research_candidates')->where('id', $existing['candidate_id'])->value('lock_version'))->toBe(3)
        ->and($revisions)->toHaveCount(3)
        ->and($revisions[1]->change_type)->toBe('status_changed')
        ->and($revisions[2]->change_type)->toBe('status_changed')
        ->and(json_decode($revisions[1]->payload, true, 512, JSON_THROW_ON_ERROR)['candidate']['status_reason'])->toBe('上場主体の公式資料を確認待ち')
        ->and(json_decode($revisions[2]->payload, true, 512, JSON_THROW_ON_ERROR)['candidate']['status_reason'])->toBe('一次資料で事業との関係が否定された');
});

test('UC-016 市場からの調査候補発見・確認は通常一覧からアーカイブを除外しテーマと状態で絞る', function () {
    uc016ReviewFixture([
        'event_title' => 'フィジカルAI発表',
        'discovery_url' => 'https://example.org/articles/physical-ai',
        'legal_name' => '物理AI社',
        'theme' => 'フィジカルAI',
    ]);
    uc016ReviewFixture([
        'event_title' => '医療AI発表',
        'discovery_url' => 'https://example.org/articles/medical-ai',
        'legal_name' => '医療AI社',
        'theme' => '医療AI',
        'status' => 'on_hold',
        'status_reason' => '上場主体の確認待ち',
    ]);
    uc016ReviewFixture([
        'event_title' => '見送り発表',
        'discovery_url' => 'https://example.org/articles/rejected',
        'legal_name' => '見送り社',
        'theme' => 'フィジカルAI',
        'status' => 'rejected',
        'status_reason' => '資料に記載がない',
    ]);
    uc016ReviewFixture([
        'event_title' => 'アーカイブ発表',
        'discovery_url' => 'https://example.org/articles/archived',
        'legal_name' => '保管済み社',
        'theme' => '医療AI',
        'archived_at' => now(),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->assertDontSee('保管済み社')
        ->set('themeFilter', '医療AI')
        ->set('statusFilter', 'on_hold')
        ->assertSee('医療AI社')
        ->assertDontSee('物理AI社')
        ->assertDontSee('見送り社')
        ->assertDontSee('保管済み社');
});

test('UC-016 市場からの調査候補発見・確認は記録日降順で20件ずつ表示する', function () {
    for ($number = 1; $number <= 22; $number++) {
        uc016ReviewFixture([
            'event_title' => "元発表{$number}",
            'discovery_url' => "https://example.org/articles/page-{$number}",
            'legal_name' => sprintf('候補法人%02d', $number),
            'created_at' => now()->subMinutes(22 - $number),
        ]);
    }

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->assertSee('候補法人22')
        ->assertSee('候補法人03')
        ->assertSeeInOrder(['候補法人22', '候補法人03'])
        ->assertDontSee('候補法人02')
        ->assertDontSee('候補法人01')
        ->call('nextPage')
        ->assertSee('候補法人02')
        ->assertSee('候補法人01')
        ->assertDontSee('候補法人22');
});

test('UC-016 市場からの調査候補発見・確認は統合解除とアーカイブ復元を専用auditチャンネルへ記録する', function () {
    $channel = config('logging.channels.audit');
    expect($channel)->toBeArray();

    $auditPath = storage_path('logs/uc016-audit-'.Str::uuid().'.jsonl');
    config()->set('logging.channels.audit.path', $auditPath);
    Log::forgetChannel('audit');

    $target = uc016ReviewFixture([
        'event_title' => '統合先の発表',
        'discovery_url' => 'https://example.org/articles/audit-target',
        'legal_name' => '統合先法人',
    ]);
    $source = uc016ReviewFixture([
        'event_title' => '統合元の転載',
        'discovery_url' => 'https://example.org/articles/audit-source',
        'legal_name' => '統合元法人',
    ]);
    $user = User::factory()->create();

    try {
        Livewire::actingAs($user)->test(CandidateResearch::class)
            ->call('mergeEvents', $source['event_id'], $target['event_id'])
            ->call('unmergeEvent', $source['event_id'])
            ->call('requestArchive', $source['candidate_id'])
            ->call('confirmArchive')
            ->call('restoreCandidate', $source['candidate_id'])
            ->assertHasNoErrors();

        expect(is_file($auditPath))->toBeTrue();
        $records = collect(file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR))
            ->map(fn ($entry) => $entry['context'] ?? $entry);

        foreach ([
            ['research_event.merged', $source['event_id'], ['research_events', 'App\\Models\\ResearchEvent']],
            ['research_event.unmerged', $source['event_id'], ['research_events', 'App\\Models\\ResearchEvent']],
            ['research_candidate.archived', $source['candidate_id'], ['research_candidates', 'App\\Models\\ResearchCandidate']],
            ['research_candidate.restored', $source['candidate_id'], ['research_candidates', 'App\\Models\\ResearchCandidate']],
        ] as [$action, $subjectId, $subjectTypes]) {
            expect($records->contains(fn ($entry) => ($entry['action'] ?? null) === $action
                && (int) ($entry['actor_id'] ?? 0) === $user->id
                && (int) ($entry['subject_id'] ?? 0) === $subjectId
                && in_array($entry['subject_type'] ?? null, $subjectTypes, true)
            ))->toBeTrue();
        }
    } finally {
        Log::forgetChannel('audit');
        if (is_file($auditPath)) {
            unlink($auditPath);
        }
    }
});
