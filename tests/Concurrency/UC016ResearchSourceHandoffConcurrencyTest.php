<?php

namespace Tests\Feature;

use App\Models\ResearchCandidate;
use App\Models\User;
use App\Services\Research\ResearchCandidateService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';
require_once __DIR__.'/../Support/ResearchAutomationReviewFixtures.php';
require_once __DIR__.'/../Support/ResearchListingCatalogFixtures.php';

// No RefreshDatabase: the competing connection must see committed fixtures.
uses(TestCase::class);

beforeEach(function () {
    // Fail closed before any schema mutation; never use the application/Feature DB.
    expect(DB::connection()->getDatabaseName())->toBe('testing_chg0031_review_concurrency');
    Artisan::call('migrate:fresh', ['--force' => true]);
    uc016AutomationConfig();
    uc016AutomationFakeHttp();
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
});

test('UC-016 市場からの調査候補発見・確認からUC-012への監視確定中に別接続の資料訂正が先に確定したら旧確認のまま受け渡さない', function () {
    $actor = User::factory()->create();
    ['draft' => $draft, 'candidate' => $candidate] = uc016DraftScreenReadyImport($actor);
    $this->travel(1)->days();
    uc016DraftScreenResponse(['Device' => 'Synthetic Concurrently Corrected Assistant']);
    $service = app(ResearchCandidateService::class);
    $prepared = $service->prepareHandoff($candidate->id, $actor);
    expect($prepared['error'])->toBeNull();

    $primaryName = DB::getDefaultConnection();
    $connection = DB::connection();
    expect($connection->transactionLevel())->toBe(0);
    config(['database.connections.uc016_competing' => config('database.connections.'.$primaryName)]);
    $competing = DB::connection('uc016_competing');
    $competing->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $original = $connection->getEventDispatcher();
    $dispatcher = clone $original;
    $attempted = false;
    $published = false;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$attempted, &$published, $draft, $primaryName, $competing): void {
        // Pause the real handoff after it reads source drafts, before it writes holdings.
        if ($attempted || ! str_starts_with(strtolower($query->sql), 'select ')
            || ! str_contains($query->sql, '`research_candidate_drafts`')
            || ! str_contains($query->sql, '`imported_candidate_id`')) {
            return;
        }
        $attempted = true;
        DB::setDefaultConnection('uc016_competing');
        try {
            expect(uc016AutomationCheckAndDiscover(true))->toBeIn([0, 1]);
            $current = $competing->table('research_candidate_drafts')->find($draft->id);
            $published = $current->current_document_version_id !== $draft->current_document_version_id;
        } finally {
            DB::setDefaultConnection($primaryName);
        }
    });
    $connection->setEventDispatcher($dispatcher);
    $handoff = null;
    try {
        try {
            $handoff = $service->confirmHandoff($candidate->id, $prepared['lock_version'],
                $prepared['idempotency_key'], '資料版の競合がないと確認して監視を選択した', $actor, $prepared['listing_id']);
        } catch (DomainException) {
            // A conflicting source revision can legitimately reject the handoff.
        }
    } finally {
        $connection->setEventDispatcher($original);
        DB::setDefaultConnection($primaryName);
        DB::disconnect('uc016_competing');
    }

    expect($attempted)->toBeTrue();
    // Both operations succeeding in this order is the forbidden outcome.
    expect($published && $handoff !== null)->toBeFalse('資料訂正が先に確定したのに旧確認で監視登録された');
    if ($published) {
        $this->assertDatabaseCount('research_watchlist_handoffs', 0);
        expect(ResearchCandidate::findOrFail($candidate->id)->status)->not->toBe('watchlisted');
    } else {
        // A source lock may instead serialize handoff first; extraction resumes after it.
        expect($handoff)->not->toBeNull();
        expect(uc016AutomationDiscover())->toBe(0);
        expect(DB::table('research_candidate_drafts')->find($draft->id)->current_document_version_id)
            ->not->toBe($draft->current_document_version_id);
    }
});
