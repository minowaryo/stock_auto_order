<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';
require_once __DIR__.'/../Support/ResearchListingCatalogFixtures.php';

/** UC-016 / ADR-0033 D5: use the real DB; advance only the wall clock, never sleep. */
beforeEach(function () {
    uc016AutomationConfig();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    uc016AutomationFakeHttp();
});

/** Expire once after a real write; the next run receives its own full budget. */
function uc016BudgetExpireAfterWrite(string $table, string $column, Closure $advance): object
{
    $state = (object) ['armed' => true, 'advanced' => false];
    DB::listen(function (QueryExecuted $query) use ($table, $column, $advance, $state): void {
        if ($state->armed && str_starts_with(strtolower($query->sql), 'update ')
            && str_contains($query->sql, '`'.$table.'`') && str_contains($query->sql, '`'.$column.'`')) {
            $state->armed = false;
            $state->advanced = true;
            $advance();
        }
    });

    return $state;
}

function uc016BudgetThreeBackfillItems(): void
{
    foreach (['K260001', 'K260002', 'K260003'] as $number) {
        uc016AutomationSourceItem([
            'url' => 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfPMN/pmn.cfm?ID='.$number,
            'metadata' => json_encode([
                'company' => 'Synthetic Medical Research Co.', 'device_name' => 'Synthetic Imaging Assistant',
                'submission_number' => $number, 'date_of_final_decision' => '2026-06-29',
                'review_panel' => 'Radiology', 'product_code' => 'QAS',
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}

test('UC-016 市場からの調査候補発見・確認は一時入力の処理中に120秒へ達したら資料単位で止めカーソルから重複なく再開する', function () {
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001'), uc016FdaRow('K260002'), uc016FdaRow('K260003'),
        ])),
    ]);
    $this->artisan('research:check-sources')->assertSuccessful();
    $checkId = DB::table('research_source_checks')->sole()->id;
    $clock = uc016BudgetExpireAfterWrite('research_document_attempts', 'output_version_id', fn () => $this->travel(121)->seconds());

    try {
        expect(uc016AutomationDiscover())->toBe(0);
    } finally {
        $clock->armed = false;
    }

    expect($clock->advanced)->toBeTrue();
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $this->assertDatabaseCount('research_document_versions', 1);
    $check = DB::table('research_source_checks')->find($checkId);
    expect($check->automation_cursor)->toBe(1)->and($check->automation_payload)->not->toBeNull();
    $run = DB::table('research_automation_runs')->latest('id')->first();
    expect($run->status)->toBe('partial')
        ->and(json_decode($run->counters, true, flags: JSON_THROW_ON_ERROR)['processed'])->toBe(1);

    expect(uc016AutomationDiscover())->toBe(0);

    $this->assertDatabaseCount('research_source_documents', 3);
    $this->assertDatabaseCount('research_candidate_drafts', 3);
    $this->assertDatabaseCount('research_document_versions', 3);
    $this->assertDatabaseCount('research_draft_revisions', 3);
    $check = DB::table('research_source_checks')->find($checkId);
    expect($check->automation_cursor)->toBeNull()->and($check->automation_payload)->toBeNull()
        ->and(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('completed');
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は導入前資料の後処理中に120秒へ達したら残りを次回へ渡し重複なく再開する', function () {
    uc016BudgetThreeBackfillItems();
    $clock = uc016BudgetExpireAfterWrite('research_document_attempts', 'output_version_id', fn () => $this->travel(121)->seconds());

    try {
        expect(uc016AutomationDiscover())->toBe(0);
    } finally {
        $clock->armed = false;
    }

    expect($clock->advanced)->toBeTrue();
    $this->assertDatabaseCount('research_source_documents', 1);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    expect(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('partial');

    expect(uc016AutomationDiscover())->toBe(0);

    $this->assertDatabaseCount('research_source_documents', 3);
    $this->assertDatabaseCount('research_candidate_drafts', 3);
    $this->assertDatabaseCount('research_document_versions', 3);
    $this->assertDatabaseCount('research_draft_revisions', 3);
    expect(DB::table('research_candidate_drafts')->where('discovery_kind', 'backfill')->count())->toBe(3)
        ->and(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('completed');
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は上場候補の照合中に120秒へ達したら残りを部分完了で持ち越し次回へ再開する', function () {
    uc016BudgetThreeBackfillItems();
    expect(uc016AutomationDiscover())->toBe(0);
    uc016CatalogConfig('sec_exchange');
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq'],
    ]));
    $clock = uc016BudgetExpireAfterWrite('research_candidate_drafts', 'match_generation', fn () => $this->travel(121)->seconds());

    try {
        expect(uc016AutomationDiscover())->toBe(0);
    } finally {
        $clock->armed = false;
    }

    expect($clock->advanced)->toBeTrue()->and(uc016CatalogActiveMatches())->toHaveCount(1)
        ->and(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('partial');

    expect(uc016AutomationDiscover())->toBe(0);

    $matches = uc016CatalogActiveMatches();
    expect($matches)->toHaveCount(3)->and($matches->pluck('draft_id')->unique())->toHaveCount(3)
        ->and(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('completed');
    $this->assertDatabaseCount('research_listing_catalog_imports', 1);
    $this->assertDatabaseCount('research_draft_listing_matches', 3);
    $this->assertDatabaseCount('research_candidate_drafts', 3);
    $this->assertDatabaseCount('research_document_versions', 3);
    $this->assertDatabaseCount('research_draft_revisions', 3);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});
