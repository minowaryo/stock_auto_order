<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\FavoriteCsvImportBatch;
use App\Models\Holding;
use App\Models\ImportBatch;
use App\Models\ResearchCandidate;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\Research\ResearchCandidateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** Missing entrypoints must fail as assertions, before new-schema setup. */
function uc016DraftScreenActions(string ...$actions): void
{
    foreach ($actions as $action) {
        expect(method_exists(CandidateResearch::class, $action))
            ->toBeTrue('UC-016 automation screen action is not implemented: '.$action);
    }
}

/** Only the public source-check/discovery commands create the draft fixtures. */
function uc016DraftScreenPrepare(array $rowOverrides = []): object
{
    uc016DraftScreenResponse($rowOverrides);
    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    return DB::table('research_candidate_drafts')->sole();
}

function uc016DraftScreenResponse(array $rowOverrides = []): void
{
    uc016AutomationFakeHttp([
        config('research_sources.S10.url') => Http::response(
            uc016AutomationFdaCsv([uc016FdaRow('K260001', $rowOverrides)]),
            200,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        ),
    ]);
}

function uc016DraftScreenManualForm(): array
{
    return [
        'theme' => '医療AI',
        'theme_relation' => '医療AI機器一覧に掲載された法人について調査する',
        'event_title' => '本人が調査した紹介資料',
        'discovery_url' => 'https://example.org/research/manual-device',
        'source_title' => '本人が選んだ紹介資料',
        'publisher' => '試験用の紹介元',
        'checked_on' => '2026-10-19',
        'summary' => '本人のメモ。元発表と上場主体は未確認。',
        'legal_name' => 'Synthetic Medical Research Co.',
        'original_url' => null,
        'original_publisher' => null,
        'announced_on' => null,
        'entity_role' => null,
        'existing_event_id' => null,
        'existing_entity_id' => null,
    ];
}

/** Existing UC-016 requirements are actually satisfied before a source changes. */
function uc016DraftScreenReadyImport(User $actor): array
{
    uc016CatalogConfig('sec_exchange');
    uc016AutomationFakeHttp([
        config('research_sources.S10.url') => Http::response(
            uc016AutomationFdaCsv([uc016FdaRow('K260001')]), 200, ['Content-Type' => 'text/csv'],
        ),
        uc016CatalogUrl('sec_exchange') => Http::response(uc016CatalogSec([
            [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq'],
        ])),
    ]);
    expect(uc016AutomationCheckAndDiscover())->toBe(0);
    $draft = DB::table('research_candidate_drafts')->sole();
    $component = Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true)->call('importDraft')->assertHasNoErrors();
    $draft = DB::table('research_candidate_drafts')->find($draft->id);
    $candidate = ResearchCandidate::findOrFail($draft->imported_candidate_id);
    $sourceUrl = DB::table('research_source_items')->sole()->url;
    $originalUrl = $candidate->event->original_url ?: $sourceUrl;
    $candidate = app(ResearchCandidateService::class)->update($candidate->id, $candidate->lock_version, [
        'original_url' => $originalUrl, 'original_publisher' => 'FDA',
        'announced_on' => '2026-10-18', 'verification_status' => 'verified', 'verified_on' => '2026-10-19',
        'theme_relation' => '医療AI一覧の原資料に対象機器の掲載を本人が確認した',
        'entity_role' => '一覧掲載企業', 'identification_status' => 'identified',
        'identification_note' => '本人が公式上場資料と原法人名を照合した',
        'market' => 'us', 'symbol_code' => 'SYNM', 'listed_entity_name' => 'Synthetic Medical Research Co.',
        'listing_source_url' => uc016CatalogUrl('sec_exchange'), 'listing_confirmed_on' => '2026-10-19',
        'counter_evidence' => '一覧掲載は売上や投資収益の証明ではない', 'counter_evidence_checked_on' => '2026-10-19',
        'claim' => 'Synthetic Imaging Assistantが対象一覧に掲載された',
        'claim_evidence_url' => $originalUrl, 'claim_status' => 'verified', 'claim_primary' => true,
        'claim_checked_on' => '2026-10-19',
    ], $actor);

    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'baseline-jp.csv', 'us_stock_filename' => 'baseline-us.csv',
        'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
    ]);
    Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $favoriteBatch = FavoriteCsvImportBatch::create(['imported_at' => now(), 'registered_count' => 1]);
    DB::table('favorite_csv_import_states')->where('id', 1)
        ->update(['latest_batch_id' => $favoriteBatch->id, 'updated_at' => now()]);
    $other = Holding::create([
        'symbol_code' => 'BASE', 'market' => 'us', 'instrument_type' => 'stock',
        'symbol_name' => 'Synthetic Baseline Corporation', 'first_detected_at' => now(),
    ]);
    WatchlistItem::create([
        'holding_id' => $other->id, 'source' => 'rakuten_favorites_csv', 'is_starred' => false,
        'registered_at' => now(), 'last_seen_in_csv_at' => now(), 'last_seen_favorite_import_id' => $favoriteBatch->id,
    ]);
    config(['research_automation.catalogs.sec_exchange.enabled' => false]);
    expect(app(ResearchCandidateService::class)->prepareHandoff($candidate->id, $actor)['error'])->toBeNull();

    return compact('draft', 'candidate', 'component');
}
