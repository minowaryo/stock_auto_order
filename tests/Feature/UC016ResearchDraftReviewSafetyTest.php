<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\ResearchCandidate;
use App\Models\User;
use App\Services\Research\ResearchCandidateService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PDOException;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';
require_once __DIR__.'/../Support/ResearchAutomationReviewFixtures.php';
require_once __DIR__.'/../Support/ResearchListingCatalogFixtures.php';

/** UC-016 / UC-012, ADR-0033 D6/D7: real writes and synthetic failures only. */
beforeEach(function () {
    uc016AutomationConfig();
    uc016AutomationFakeHttp();
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
});

test('UC-016 市場からの調査候補発見・確認は保存障害のSQLや本人追記を画面に出さず版競合と区別して全体を戻す', function (string $action) {
    $actor = User::factory()->create();
    $draft = uc016DraftScreenPrepare();
    $component = Livewire::actingAs($actor)->test(CandidateResearch::class)
        ->call('showDraft', $draft->id)->set('draftConfirmed', true);
    if ($action === 'reconfirmDraft') {
        $component->call('importDraft')->assertHasNoErrors()
            ->call('showDraft', $draft->id)->set('draftConfirmed', true);
    }
    $marker = 'SYNTHETIC_PRIVATE_NOTE_SHOULD_NOT_APPEAR';
    $component->set('draftNote', $marker);
    $tables = ['research_events', 'research_entities', 'research_candidates', 'research_discovery_links',
        'research_candidate_revisions', 'research_candidate_drafts', 'research_draft_revisions'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    uc016AutomationFakeHttp();
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $dispatcher = clone $original;
    $injected = false;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$injected, $marker): void {
        if (! $injected && str_starts_with(strtolower($query->sql), 'insert into `research_draft_revisions`')) {
            $injected = true;
            throw new QueryException($query->connectionName, 'insert into research_draft_revisions (payload) values (?)',
                [$marker], new PDOException('Synthetic storage failure'));
        }
    });
    $connection->setEventDispatcher($dispatcher);
    try {
        $component->call($action)->assertHasErrors(['draft']);
    } finally {
        $connection->setEventDispatcher($original);
    }

    expect($injected)->toBeTrue();
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($before[$table]);
    }
    $message = $component->instance()->getErrorBag()->first('draft');
    expect($message)->not->toBeEmpty()->not->toContain($marker)->not->toContain('SQL:')
        ->not->toContain('Connection:')->not->toContain('research_draft_revisions')
        ->not->toContain('最新版')->not->toContain('版競合');
    Http::assertNothingSent();
})->with(['import' => ['importDraft'], 'reconfirm' => ['reconfirmDraft']]);

test('UC-016 市場からの調査候補発見・確認からUC-012へは選択した市場の公式照合で判定し他市場の欠測で妨げない', function (string $operation, string $market) {
    $actor = User::factory()->create();
    ['draft' => $draft, 'candidate' => $candidate] = uc016DraftScreenReadyImport($actor);
    $this->travel(25)->hours();
    uc016CatalogConfig('jpx_tse');
    uc016CatalogConfig('sec_exchange', ['user_agent' => '']);
    uc016CatalogResponses('jpx_tse', uc016CatalogXlsx('jpx-mixed-formal-codes.xlsx'));
    expect(uc016AutomationDiscover())->toBe(0);
    $entry = DB::table('research_listing_catalog_entries')->where('market', 'jp')->where('symbol_code', '2345')->sole();
    $service = app(ResearchCandidateService::class);
    $candidate = $service->update($candidate->id, $candidate->fresh()->lock_version, ['listings' => [[
        'market' => 'jp', 'symbol_code' => '2345', 'listed_entity_name' => $entry->legal_name,
        'source_url' => uc016CatalogUrl('jpx_tse'), 'confirmed_on' => '2026-10-20',
    ]]], $actor);
    Livewire::actingAs($actor)->test(CandidateResearch::class)->call('showDraft', $draft->id)
        ->set('draftConfirmed', true)->call('reconfirmDraft')->assertHasNoErrors();
    $candidate = ResearchCandidate::findOrFail($candidate->id);
    $listing = $candidate->entity->listings()->where('market', $market)->sole();
    expect(DB::table('research_listing_catalog_imports')->where('catalog_key', 'jpx_tse')->sole()->status)->toBe('published')
        ->and(DB::table('research_listing_catalog_imports')->where('catalog_key', 'sec_exchange')->latest('id')->first()->status)->toBe('blocked');
    uc016AutomationFakeHttp();

    if ($operation === 'prepare') {
        $review = $service->prepareHandoff($candidate->id, $actor, $listing->id);
        expect($review['listing_id'])->toBe($listing->id);
        if ($market === 'jp') {
            expect($review['error'])->toBeNull();
        } else {
            expect($review['error'])->not->toBeNull();
        }
        $this->assertDatabaseCount('research_watchlist_handoffs', 0);
    } elseif ($market === 'jp') {
        $handoff = null;
        $failure = null;
        try {
            $handoff = $service->confirmHandoff($candidate->id, $candidate->lock_version, (string) Str::uuid(),
                '選択した日本株の公式資料と上場主体を確認した', $actor, $listing->id);
        } catch (DomainException $exception) {
            $failure = $exception->getMessage();
        }
        expect($failure)->toBeNull();
        expect($handoff->research_entity_listing_id)->toBe($listing->id)->and($handoff->outcome)->toBe('created');
        $this->assertDatabaseCount('research_watchlist_handoffs', 1);
        $this->assertDatabaseHas('holdings', ['market' => 'jp', 'symbol_code' => '2345']);
    } else {
        expect(fn () => $service->confirmHandoff($candidate->id, $candidate->lock_version, (string) Str::uuid(),
            '米国株を選択したがSECは確認できていない', $actor, $listing->id))->toThrow(DomainException::class);
        $this->assertDatabaseCount('research_watchlist_handoffs', 0);
    }
    Http::assertNothingSent();
})->with([
    'JP prepare' => ['prepare', 'jp'], 'US prepare' => ['prepare', 'us'],
    'JP confirm' => ['confirm', 'jp'], 'US confirm' => ['confirm', 'us'],
]);
