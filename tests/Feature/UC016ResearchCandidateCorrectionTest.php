<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\Holding;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\Research\ResearchCandidateService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** UC-016「市場からの調査候補発見・確認」: 訂正後の根拠と複数行編集。 */
function uc016CorrectionCandidate(User $actor, string $suffix = 'a')
{
    return app(ResearchCandidateService::class)->create([
        'theme' => 'フィジカルAI',
        'theme_relation' => '設備メーカーの供給関係を調べる',
        'discovery_url' => "https://example.org/discovery/{$suffix}",
        'source_title' => '公開記事',
        'publisher' => '紹介元',
        'checked_on' => '2026-10-04',
        'summary' => '元発表の内容を確認する。',
        'legal_name' => "検証用企業{$suffix}",
    ], $actor);
}

function uc016CorrectionVerifiedCandidate(User $actor)
{
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionCandidate($actor);

    return $service->update($candidate->id, $candidate->lock_version, [
        'original_url' => 'https://example.org/press/original',
        'original_publisher' => '発表企業',
        'announced_on' => '2026-10-01',
        'verification_status' => 'verified',
        'verified_on' => '2026-10-04',
        'entity_role' => '供給者',
        'counter_evidence' => '売上寄与は未確認',
        'counter_evidence_checked_on' => '2026-10-04',
        'identification_status' => 'identified',
        'market' => 'jp',
        'symbol_code' => '1234',
        'listed_entity_name' => '検証用企業a',
        'listing_source_url' => 'https://example.org/listing/a',
        'listing_confirmed_on' => '2026-10-04',
        'claims' => [
            [
                'claim' => '発表企業が製品を公表した',
                'evidence_url' => 'https://example.org/press/original',
                'status' => 'verified',
                'is_primary_source' => true,
                'checked_on' => '2026-10-04',
            ],
        ],
    ], $actor);
}

function uc016CorrectionBaseline(): void
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp.csv',
        'us_stock_filename' => 'us.csv',
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);
    Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $other = Holding::create([
        'symbol_code' => '9999',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => '照合用別銘柄',
        'first_detected_at' => now(),
    ]);
    WatchlistItem::create([
        'holding_id' => $other->id,
        'source' => 'rakuten_favorites_csv',
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);
}

test('UC-016 市場からの調査候補発見・確認は元発表URL訂正時に旧URLを根拠にした主張だけ再確認へ戻し旧版を残す', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $oldClaim = $candidate->claims()->firstOrFail();
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'claims' => [[
            'claim' => '別の公式資料が採択を発表した',
            'evidence_url' => 'https://example.net/official/award',
            'status' => 'verified',
            'is_primary_source' => true,
            'checked_on' => '2026-10-04',
        ]],
    ], $actor);
    $independentClaim = $candidate->claims()->where('evidence_url', 'https://example.net/official/award')->firstOrFail();
    $oldVersion = $candidate->lock_version;

    $updated = $service->update($candidate->id, $oldVersion, [
        'original_url' => 'https://example.org/press/corrected',
    ], $actor);

    expect($oldClaim->fresh()->status)->toBe('unverified')
        ->and($oldClaim->fresh()->checked_on)->toBeNull()
        ->and($independentClaim->fresh()->status)->toBe('verified')
        ->and($independentClaim->fresh()->checked_on?->format('Y-m-d'))->toBe('2026-10-04')
        ->and($updated->lock_version)->toBe($oldVersion + 1);
    $before = $candidate->revisions()->where('lock_version', $oldVersion)->firstOrFail()->payload;
    $after = $updated->revisions()->where('lock_version', $updated->lock_version)->firstOrFail()->payload;
    expect(collect($before['claims'])->firstWhere('id', $oldClaim->id)['status'])->toBe('verified')
        ->and(collect($before['claims'])->firstWhere('id', $oldClaim->id)['checked_on'])->not->toBeNull()
        ->and(collect($after['claims'])->firstWhere('id', $oldClaim->id)['status'])->toBe('unverified')
        ->and(collect($after['claims'])->firstWhere('id', $oldClaim->id)['checked_on'])->toBeNull()
        ->and($before['event']['original_url'])->toBe('https://example.org/press/original')
        ->and($after['event']['original_url'])->toBe('https://example.org/press/corrected');
});

test('UC-016 市場からの調査候補発見・確認は確認済み元発表URLの訂正後に元発表の再確認まで監視へ渡さない', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $originalClaim = $candidate->claims()->firstOrFail();
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'claims' => [[
            'claim' => '別の公式資料で導入先を確認した',
            'evidence_url' => 'https://example.net/official/deployment',
            'status' => 'verified',
            'is_primary_source' => true,
            'checked_on' => '2026-10-04',
        ]],
    ], $actor);
    $independentClaim = $candidate->claims()->where('evidence_url', 'https://example.net/official/deployment')->firstOrFail();
    uc016CorrectionBaseline();

    // The full edit form carries the old verification fields along with the changed URL.
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'original_url' => 'https://example.org/press/replacement',
        'verification_status' => 'verified',
        'verified_on' => '2026-10-04',
    ], $actor);

    expect($candidate->event->fresh()->verification_status)->toBe('unverified')
        ->and($candidate->event->fresh()->verified_on)->toBeNull()
        ->and($originalClaim->fresh()->status)->toBe('unverified')
        ->and($originalClaim->fresh()->checked_on)->toBeNull()
        ->and($independentClaim->fresh()->status)->toBe('verified')
        ->and($independentClaim->fresh()->checked_on?->format('Y-m-d'))->toBe('2026-10-04')
        ->and($service->prepareHandoff($candidate->id, $actor)['error'])->toBe('元発表と発表日を一次資料で確認してください。')
        ->and(DB::table('research_watchlist_handoffs')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は元発表URLを変えない説明の訂正では確認状態を保つ', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);

    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'original_url' => 'https://example.org/press/original',
        'original_publisher' => '発表企業の正式名称',
        'verification_status' => 'verified',
        'verified_on' => '2026-10-04',
    ], $actor);

    expect($candidate->event->fresh()->verification_status)->toBe('verified')
        ->and($candidate->event->fresh()->verified_on?->format('Y-m-d'))->toBe('2026-10-04')
        ->and($candidate->claims()->firstOrFail()->status)->toBe('verified');
});

test('UC-016 市場からの調査候補発見・確認は訂正先の元発表を別操作で再確認すると受け渡しを準備できる', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'claims' => [[
            'claim' => '別の公式資料で導入先を確認した',
            'evidence_url' => 'https://example.net/official/deployment',
            'status' => 'verified',
            'is_primary_source' => true,
            'checked_on' => '2026-10-04',
        ]],
    ], $actor);
    uc016CorrectionBaseline();

    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'original_url' => 'https://example.org/press/replacement',
        'verification_status' => 'verified',
        'verified_on' => '2026-10-04',
    ], $actor);
    expect($candidate->event->fresh()->verification_status)->toBe('unverified')
        ->and($service->prepareHandoff($candidate->id, $actor)['error'])->toBe('元発表と発表日を一次資料で確認してください。');

    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'verification_status' => 'verified',
        'verified_on' => '2026-10-05',
    ], $actor);

    expect($candidate->event->fresh()->verification_status)->toBe('verified')
        ->and($candidate->event->fresh()->verified_on?->format('Y-m-d'))->toBe('2026-10-05')
        ->and($service->prepareHandoff($candidate->id, $actor)['error'])->toBeNull();
});

test('UC-016 市場からの調査候補発見・確認は元発表の確認不能時に紐づく主張を再確認へ戻す', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $claim = $candidate->claims()->firstOrFail();

    $service->update($candidate->id, $candidate->lock_version, [
        'verification_status' => 'unavailable',
        'verified_on' => null,
    ], $actor);

    expect($claim->fresh()->status)->toBe('unverified')
        ->and($claim->fresh()->checked_on)->toBeNull()
        ->and($candidate->fresh()->event->verification_status)->toBe('unavailable');
});

test('UC-016 市場からの調査候補発見・確認は元資料訂正後の古い主張だけで監視受け渡しを準備しない', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    uc016CorrectionBaseline();
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'original_url' => 'https://example.org/press/replacement',
    ], $actor);

    expect($service->prepareHandoff($candidate->id, $actor)['error'])->toBe('元発表と発表日を一次資料で確認してください。')
        ->and(DB::table('research_watchlist_handoffs')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は画面で2件目の主張を選び訂正して旧版を残す', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $first = $candidate->claims()->firstOrFail();
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'claims' => [[
            'claim' => '売上寄与は確認できない',
            'evidence_url' => 'https://example.net/official/b',
            'status' => 'unverified',
            'is_primary_source' => true,
            'checked_on' => '2026-10-04',
        ]],
    ], $actor);
    $second = $candidate->claims()->orderByDesc('id')->firstOrFail();
    $oldVersion = $candidate->lock_version;

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->assertSee('selectClaimForEdit('.$second->id.')', false)
        ->call('selectClaimForEdit', $second->id)
        ->assertSet('claimForm.claim', '売上寄与は確認できない')
        ->set('claimForm.claim', '公式資料で売上寄与を確認した')
        ->set('claimForm.status', 'verified')
        ->call('saveClaimChanges')
        ->assertHasNoErrors();

    expect($first->fresh()->claim)->toBe('発表企業が製品を公表した')
        ->and($second->fresh()->claim)->toBe('公式資料で売上寄与を確認した')
        ->and($candidate->fresh()->lock_version)->toBe($oldVersion + 1)
        ->and(collect($candidate->revisions()->where('lock_version', $oldVersion)->firstOrFail()->payload['claims'])->firstWhere('id', $second->id)['claim'])->toBe('売上寄与は確認できない');
});

test('UC-016 市場からの調査候補発見・確認は画面で未確認主張を確認日空欄のまま追記できる', function () {
    $actor = User::factory()->create();
    $candidate = uc016CorrectionCandidate($actor);

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->set('claimForm.claim', '売上への寄与は未確認')
        ->set('claimForm.evidence_url', 'https://example.org/claims/unverified')
        ->set('claimForm.status', 'unverified')
        ->set('claimForm.checked_on', '')
        ->call('addClaim')
        ->assertHasNoErrors()
        ->assertSet('notice', '主張と根拠を新しい版に追記しました。');

    $claim = $candidate->claims()->sole();
    expect($claim->status)->toBe('unverified')
        ->and($claim->checked_on)->toBeNull();
});

test('UC-016 市場からの調査候補発見・確認は画面で確認済み主張を未確認へ訂正すると確認日を空欄にできる', function () {
    $actor = User::factory()->create();
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $claim = $candidate->claims()->sole();

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->call('selectClaimForEdit', $claim->id)
        ->set('claimForm.status', 'unverified')
        ->set('claimForm.checked_on', '')
        ->call('saveClaimChanges')
        ->assertHasNoErrors()
        ->assertSet('notice', '主張の訂正を新しい版として保存しました。');

    expect($claim->fresh()->status)->toBe('unverified')
        ->and($claim->fresh()->checked_on)->toBeNull();
});

test('UC-016 市場からの調査候補発見・確認は画面で2件目の上場先を選び訂正して1件目を保持する', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $first = $candidate->entity->listings()->firstOrFail();
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'listings' => [[
            'market' => 'us',
            'symbol_code' => 'EXA',
            'listed_entity_name' => 'Example A',
            'source_url' => 'https://example.net/listing/us',
            'confirmed_on' => '2026-10-04',
        ]],
    ], $actor);
    $second = $candidate->entity->listings()->orderByDesc('id')->firstOrFail();
    $oldVersion = $candidate->lock_version;

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->assertSee('selectListingForEdit('.$second->id.')', false)
        ->call('selectListingForEdit', $second->id)
        ->assertSet('listingForm.symbol_code', 'EXA')
        ->set('listingForm.symbol_code', 'EXB')
        ->set('listingForm.listed_entity_name', 'Example B')
        ->call('saveListingChanges')
        ->assertHasNoErrors();

    expect($first->fresh()->symbol_code)->toBe('1234')
        ->and($second->fresh()->symbol_code)->toBe('EXB')
        ->and($candidate->fresh()->lock_version)->toBe($oldVersion + 1)
        ->and(collect($candidate->revisions()->where('lock_version', $oldVersion)->firstOrFail()->payload['entity_listings'])->firstWhere('id', $second->id)['symbol_code'])->toBe('EXA');
});

test('UC-016 市場からの調査候補発見・確認は他候補の主張と他法人の上場先を編集対象にできない', function () {
    $actor = User::factory()->create();
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $other = uc016CorrectionCandidate($actor, 'other');
    $other = app(ResearchCandidateService::class)->update($other->id, $other->lock_version, [
        'claims' => [[
            'claim' => '他候補だけの主張',
            'evidence_url' => 'https://example.net/other/claim',
            'status' => 'unverified',
            'is_primary_source' => false,
        ]],
        'listings' => [[
            'market' => 'us',
            'symbol_code' => 'OTHER',
            'listed_entity_name' => 'Other',
            'source_url' => 'https://example.net/other/listing',
            'confirmed_on' => '2026-10-04',
        ]],
    ], $actor);

    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->call('selectClaimForEdit', $other->claims()->firstOrFail()->id)
        ->assertNotFound();
    Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->call('selectListingForEdit', $other->entity->listings()->firstOrFail()->id)
        ->assertNotFound();

    expect(DB::table('research_claims')->where('claim', '他候補だけの主張')->count())->toBe(1)
        ->and(DB::table('research_entity_listings')->where('symbol_code', 'OTHER')->count())->toBe(1);
});

test('UC-016 市場からの調査候補発見・確認は2件目の主張編集で版競合を拒否する', function () {
    $actor = User::factory()->create();
    $service = app(ResearchCandidateService::class);
    $candidate = uc016CorrectionVerifiedCandidate($actor);
    $candidate = $service->update($candidate->id, $candidate->lock_version, [
        'claims' => [[
            'claim' => '競合を確認する主張',
            'evidence_url' => 'https://example.net/official/second',
            'status' => 'unverified',
            'is_primary_source' => true,
        ]],
    ], $actor);
    $second = $candidate->claims()->orderByDesc('id')->firstOrFail();
    $editing = Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('editCandidate', $candidate->id)
        ->call('selectClaimForEdit', $second->id)
        ->set('claimForm.claim', '競合前の入力');
    $service->update($candidate->id, $candidate->lock_version, ['theme_relation' => '別操作による更新'], $actor);

    $editing->call('saveClaimChanges')->assertHasErrors();

    expect($second->fresh()->claim)->toBe('競合を確認する主張')
        ->and($candidate->fresh()->lock_version)->toBe($candidate->lock_version + 1);
});
