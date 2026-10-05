<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\User;
use App\Services\Research\ResearchCandidateService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** UC-016追加Red: 複数根拠と変更前後の画面操作。Gate 4承認前に実装しない。 */
function uc016EvidenceCandidate(User $actor)
{
    return app(ResearchCandidateService::class)->create([
        'theme' => 'フィジカルAI',
        'theme_relation' => '旧: 搬送ロボットの供給関係を調査中',
        'discovery_url' => 'https://example.org/uc016/evidence',
        'source_title' => '紹介記事',
        'publisher' => '紹介元',
        'checked_on' => '2026-10-04',
        'summary' => '紹介資料を確認した。',
        'legal_name' => '検証用ロボット株式会社',
    ], $actor);
}

test('UC-016 市場からの調査候補発見・確認は画面から2件目の主張と根拠を追記し各版を残す', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016EvidenceCandidate($actor);
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'claim' => '企業が研究採択を発表した',
        'claim_evidence_url' => 'https://example.org/uc016/primary-a',
        'claim_status' => 'verified',
        'claim_primary' => true,
        'claim_checked_on' => '2026-10-04',
    ], $actor);

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->set('claimForm', [
            'claim' => '量産の売上寄与は未確認',
            'evidence_url' => 'https://example.org/uc016/primary-b',
            'status' => 'unverified',
            'is_primary_source' => true,
            'checked_on' => '2026-10-04',
        ])
        ->call('addClaim')
        ->assertHasNoErrors();

    expect(DB::table('research_claims')->where('research_candidate_id', $candidate->id)->count())->toBe(2)
        ->and($candidate->fresh()->lock_version)->toBe(3)
        ->and($candidate->revisions()->count())->toBe(3);
});

test('UC-016 市場からの調査候補発見・確認は画面から2件目の上場先と確認元を追記する', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016EvidenceCandidate($actor);
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'market' => 'jp',
        'symbol_code' => '1234',
        'listed_entity_name' => '検証用ロボット株式会社',
        'listing_source_url' => 'https://example.org/uc016/listing-jp',
        'listing_confirmed_on' => '2026-10-04',
    ], $actor);

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->set('listingForm', [
            'market' => 'us',
            'symbol_code' => 'EXRB',
            'listed_entity_name' => 'Example Robotics',
            'source_url' => 'https://example.org/uc016/listing-us',
            'confirmed_on' => '2026-10-04',
        ])
        ->call('addListing')
        ->assertHasNoErrors();

    expect(DB::table('research_entity_listings')->where('research_entity_id', $candidate->research_entity_id)->count())->toBe(2)
        ->and($candidate->fresh()->lock_version)->toBe(3)
        ->and($candidate->revisions()->count())->toBe(3);
});

test('UC-016 市場からの調査候補発見・確認は履歴に訂正前後の記述を表示する', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016EvidenceCandidate($actor);
    $service->update($candidate->id, $candidate->lock_version, [
        'theme_relation' => '新: 供給関係は一次資料で確認できなかった',
    ], $actor);

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('selectCandidate', $candidate->id)
        ->assertSee('旧: 搬送ロボットの供給関係を調査中')
        ->assertSee('新: 供給関係は一次資料で確認できなかった');
});
