<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';
require_once __DIR__.'/../Support/ResearchListingCatalogFixtures.php';

/** UC-016 市場からの調査候補発見・確認 / ADR-0033 D4 / official JPX code format. */
beforeEach(function () {
    uc016AutomationConfig();
    uc016CatalogConfig('jpx_tse');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    uc016CatalogResponses('jpx_tse', uc016CatalogXlsx('jpx-mixed-formal-codes.xlsx'));
});

test('UC-016 市場からの調査候補発見・確認はJPXの正式5桁コードを丸めず普通株と同じ全件公開版へ保存する', function () {
    expect(uc016AutomationDiscover())->toBe(0);

    $import = DB::table('research_listing_catalog_imports')->sole();
    expect($import->status)->toBe('published')->and($import->row_count)->toBe(4)
        ->and($import->as_of_on)->toBe('2026-09-30')->and($import->published_at)->not->toBeNull();
    expect(DB::table('research_listing_catalog_entries')->where('import_id', $import->id)->pluck('symbol_code')->all())
        ->toEqualCanonicalizing(['2345', '23455', '523A', '523A5']);
    $ordinary = DB::table('research_listing_catalog_entries')->where('symbol_code', '2345')->sole();
    $preferred = DB::table('research_listing_catalog_entries')->where('symbol_code', '23455')->sole();
    expect($preferred->legal_name)->toBe($ordinary->legal_name)
        ->and($preferred->id)->not->toBe($ordinary->id)->and($preferred->issuer_key)->not->toBe($ordinary->issuer_key);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は同名のJPX普通株4桁だけを自動照合し正式5桁の別名も普通株へ混同しない', function () {
    uc016AutomationSourceItem();

    expect(uc016AutomationDiscover())->toBe(0);
    expect(DB::table('research_listing_catalog_imports')->sole()->status)->toBe('published');
    $entry = DB::table('research_listing_catalog_entries')->where('symbol_code', '23455')->sole();
    uc016CatalogAlias($entry->id, 'Synthetic Medical Research Co.');

    expect(uc016AutomationDiscover())->toBe(0);

    $matches = uc016CatalogActiveMatches();
    expect($matches)->toHaveCount(1)->and($matches->sole()->match_method)->toBe('exact_name');
    expect(DB::table('research_listing_catalog_entries')->find($matches->sole()->entry_id)->symbol_code)->toBe('2345');
    $this->assertDatabaseCount('research_company_aliases', 1);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はJPX正式5桁だけの名称一致を企業下書きに残し4桁コードを推測しない', function () {
    uc016AutomationSourceItem(['metadata' => json_encode([
        'company' => 'Synthetic Preferred Only Corporation', 'device_name' => 'Synthetic Assistant', 'submission_number' => 'K260001',
        'date_of_final_decision' => '2026-06-29', 'review_panel' => 'Radiology', 'product_code' => 'QAS',
    ], JSON_THROW_ON_ERROR)]);

    expect(uc016AutomationDiscover())->toBe(0);

    expect(DB::table('research_listing_catalog_imports')->sole()->status)->toBe('published');
    $this->assertDatabaseHas('research_listing_catalog_entries', [
        'symbol_code' => '523A5', 'legal_name' => 'Synthetic Preferred Only Corporation',
    ]);
    $this->assertDatabaseHas('research_listing_catalog_entries', [
        'symbol_code' => '523A', 'legal_name' => 'Synthetic Other Ordinary Corporation',
    ]);
    expect(DB::table('research_candidate_drafts')->sole()->raw_name)->toBe('Synthetic Preferred Only Corporation');
    expect(uc016CatalogActiveMatches())->toHaveCount(0);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});
