<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';
require_once __DIR__.'/../Support/ResearchListingCatalogFixtures.php';

/** UC-016 市場からの調査候補発見・確認 / R5・R8 / ADR-0033 D4. */
beforeEach(function () {
    uc016AutomationConfig();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    uc016AutomationFakeHttp([]);
});

test('UC-016 市場からの調査候補発見・確認はJPX公式Xlsxの基準日と英字入りコードを全件公開する', function () {
    uc016CatalogConfig('jpx_tse');
    uc016AutomationSourceItem(['metadata' => json_encode([
        'company' => '仮想医療技術株式会社', 'device_name' => 'Synthetic Assistant', 'submission_number' => 'K260001',
        'date_of_final_decision' => '2026-06-29', 'review_panel' => 'Radiology', 'product_code' => 'QAS',
    ], JSON_THROW_ON_ERROR)]);
    uc016CatalogResponses('jpx_tse', uc016CatalogXlsx());

    expect(uc016AutomationDiscover())->toBe(0);

    $import = DB::table('research_listing_catalog_imports')->sole();
    expect($import->status)->toBe('published')->and($import->as_of_on)->toBe('2026-09-30')
        ->and($import->row_count)->toBe(2)->and($import->published_at)->not->toBeNull()
        ->and($import->content_hash)->toBe(hash('sha256', uc016CatalogXlsx()));
    $this->assertDatabaseHas('research_listing_catalog_entries', [
        'import_id' => $import->id, 'market' => 'jp', 'symbol_code' => '523A',
        'legal_name' => '仮想医療技術株式会社',
    ]);
    expect(uc016CatalogActiveMatches())->toHaveCount(1);
    $matchedEntry = DB::table('research_listing_catalog_entries')->find(uc016CatalogActiveMatches()->sole()->entry_id);
    expect($matchedEntry->symbol_code)->toBe('523A')->and($matchedEntry->legal_name)->toBe('仮想医療技術株式会社');
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はSECのfields順を使い同じCIKの複数ティッカーを同発行体候補にする', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        ['SYNM', 'Nasdaq', 'Synthetic Medical Research Co.', 9999001],
        ['SYNM.A', 'NYSE', 'Synthetic Medical Research Co.', 9999001],
    ], ['ticker', 'exchange', 'name', 'cik']));

    expect(uc016AutomationDiscover())->toBe(0);

    $import = DB::table('research_listing_catalog_imports')->sole();
    expect($import->status)->toBe('published')->and($import->as_of_on)->toBeNull()->and($import->row_count)->toBe(2);
    $entries = DB::table('research_listing_catalog_entries')->get();
    expect($entries->pluck('issuer_key')->unique())->toHaveCount(1)
        ->and($entries->pluck('symbol_code')->all())->toEqualCanonicalizing(['SYNM', 'SYNM.A'])
        ->and(uc016CatalogActiveMatches())->toHaveCount(2);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('research_candidates', 0);
});

test('UC-016 市場からの調査候補発見・確認は空白全半角だけを整理して法人格や句読点を勝手に除去しない', function (string $name, int $matches) {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem(['metadata' => json_encode([
        'company' => $name, 'device_name' => 'Synthetic Assistant', 'submission_number' => 'K260001',
        'date_of_final_decision' => '2026-06-29', 'review_panel' => 'Radiology', 'product_code' => 'QAS',
    ], JSON_THROW_ON_ERROR)]);
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq']]));

    expect(uc016AutomationDiscover())->toBe(0);

    expect(uc016CatalogActiveMatches())->toHaveCount($matches);
    $this->assertDatabaseCount('research_entity_listings', 0);
})->with([
    'NFKC and consecutive spaces' => ['Ｓｙｎｔｈｅｔｉｃ　Medical   Research Co.', 1],
    'legal suffix removed is not exact' => ['Synthetic Medical Research', 0],
    'punctuation removed is not exact' => ['Synthetic Medical Research Co', 0],
]);

test('UC-016 市場からの調査候補発見・確認は同じ名前の異なるCIKを両方残し上場主体を自動確定しない', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq'],
        [9999002, 'Synthetic Medical Research Co.', 'SYNX', 'NYSE'],
    ]));

    expect(uc016AutomationDiscover())->toBe(0);

    expect(uc016CatalogActiveMatches())->toHaveCount(2)
        ->and(DB::table('research_listing_catalog_entries')->pluck('issuer_key')->unique())->toHaveCount(2);
    $this->assertDatabaseCount('research_entities', 0);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はSECのOTCと市場欠測を取引所上場候補にしない', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'OTC'],
        [9999002, 'Synthetic Medical Research Co.', 'SYNX', null],
        [9999003, 'Synthetic Unrelated Corporation', 'SYNU', 'Nasdaq'],
    ]));

    expect(uc016AutomationDiscover())->toBe(0);

    expect(uc016CatalogActiveMatches())->toHaveCount(0);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認は公式マスタ欠測でも企業下書きを残し未登録銘柄を作らない', function () {
    uc016AutomationSourceItem();
    uc016AutomationFakeHttp(['*' => Http::response('UNEXPECTED_EXTERNAL_ACCESS', 500)]);

    expect(uc016AutomationDiscover())->toBe(0);

    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $this->assertDatabaseCount('research_listing_catalog_imports', 0);
    expect(uc016CatalogActiveMatches())->toHaveCount(0);
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認はSEC識別用UserAgent未設定ならHTTPせず欠測を記録する', function () {
    uc016CatalogConfig('sec_exchange', ['user_agent' => '']);
    uc016AutomationSourceItem();
    uc016AutomationFakeHttp(['*' => Http::response('MUST_NOT_FETCH', 500)]);

    uc016AutomationDiscover();

    $import = DB::table('research_listing_catalog_imports')->sole();
    expect($import->status)->toBe('blocked')->and($import->published_at)->toBeNull()->and($import->row_count)->toBe(0);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認はSEC取得を設定した公式URLと識別用UserAgentに限る', function () {
    uc016CatalogConfig('sec_exchange');
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq']]));

    expect(uc016AutomationDiscover())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === uc016CatalogUrl('sec_exchange')
        && $request->method() === 'GET'
        && $request->hasHeader('User-Agent', 'SyntheticResearchTest test@example.invalid'));
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は公式マスタのリダイレクトを辿らず失敗にする', function (string $key) {
    uc016CatalogConfig($key);
    uc016CatalogResponses($key, '', 302, ['Location' => 'https://example.invalid/private-catalog']);

    uc016AutomationDiscover();

    expect(DB::table('research_listing_catalog_imports')->sole()->status)->toBe('failed');
    $this->assertDatabaseCount('research_listing_catalog_entries', 0);
    Http::assertSentCount(1);
})->with(['jpx_tse', 'sec_exchange']);

test('UC-016 市場からの調査候補発見・確認は壊れた公式マスタを一部公開せず全件失敗にする', function (string $key, string $body) {
    uc016CatalogConfig($key);
    uc016CatalogResponses($key, $body);

    uc016AutomationDiscover();

    $import = DB::table('research_listing_catalog_imports')->sole();
    expect($import->status)->toBe('failed')->and($import->published_at)->toBeNull()->and($import->row_count)->toBe(0);
    $this->assertDatabaseCount('research_listing_catalog_entries', 0);
    expect(uc016CatalogActiveMatches())->toHaveCount(0);
})->with([
    'SEC zero data' => ['sec_exchange', uc016CatalogSec()],
    'SEC valid row then invalid ticker' => ['sec_exchange', uc016CatalogSec([
        [9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq'], [9999002, 'Other Synthetic Co.', 'INVALID/TICKER', 'NYSE'],
    ])],
    'JPX zero rows' => ['jpx_tse', uc016CatalogXlsx('jpx-zero.xlsx')],
    'JPX valid row then invalid code' => ['jpx_tse', uc016CatalogXlsx('jpx-invalid-row.xlsx')],
    'JPX required cell is a formula' => ['jpx_tse', uc016CatalogXlsx('jpx-formula.xlsx')],
    'JPX expanded ZIP exceeds 64 MiB' => ['jpx_tse', uc016CatalogXlsx('jpx-overexpanded.xlsx')],
]);

test('UC-016 市場からの調査候補発見・確認は応答上限超過時にマスタを公開しない', function () {
    uc016CatalogConfig('sec_exchange', ['max_response_bytes' => 32]);
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq']]));

    uc016AutomationDiscover();

    expect(DB::table('research_listing_catalog_imports')->sole()->status)->toBe('failed');
    $this->assertDatabaseCount('research_listing_catalog_entries', 0);
});

test('UC-016 市場からの調査候補発見・確認は次のマスタ取得に失敗しても前の成功版を上書きしない', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq']]));
    expect(uc016AutomationDiscover())->toBe(0);
    $published = DB::table('research_listing_catalog_imports')->sole();
    $entries = DB::table('research_listing_catalog_entries')->get();
    $this->travel(25)->hours();
    uc016CatalogResponses('sec_exchange', 'PRIVATE_RESPONSE_MUST_NOT_BE_STORED', 503);

    uc016AutomationDiscover();

    expect(DB::table('research_listing_catalog_imports')->find($published->id))->toEqual($published)
        ->and(DB::table('research_listing_catalog_entries')->get())->toEqual($entries)
        ->and(DB::table('research_listing_catalog_imports')->latest('id')->first()->status)->toBe('failed');
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認はJPXの古い基準日へ公開版を巻き戻さない', function () {
    uc016CatalogConfig('jpx_tse');
    uc016CatalogResponses('jpx_tse', uc016CatalogXlsx());
    expect(uc016AutomationDiscover())->toBe(0);
    $published = DB::table('research_listing_catalog_imports')->sole();
    $this->travel(8)->days();
    uc016CatalogResponses('jpx_tse', uc016CatalogXlsx('jpx-older.xlsx'));

    uc016AutomationDiscover();

    expect(DB::table('research_listing_catalog_imports')->where('status', 'published')->pluck('id')->all())->toBe([$published->id])
        ->and(DB::table('research_listing_catalog_imports')->latest('id')->first()->status)->toBe('failed');
    $this->assertDatabaseCount('research_listing_catalog_entries', 2);
});

test('UC-016 市場からの調査候補発見・確認はマスタの通常取得間隔内に外部へ再アクセスしない', function (string $key, int $hours) {
    uc016CatalogConfig($key);
    uc016CatalogResponses($key, $key === 'jpx_tse' ? uc016CatalogXlsx() : uc016CatalogSec([[9999001, 'Synthetic Medical Research Co.', 'SYNM', 'Nasdaq']]));
    expect(uc016AutomationDiscover())->toBe(0);
    $this->travel($hours - 1)->hours();
    uc016CatalogResponses($key, 'MUST_NOT_REFETCH', 500);

    expect(uc016AutomationDiscover())->toBe(0);

    Http::assertNothingSent();
    $this->assertDatabaseCount('research_listing_catalog_imports', 1);
})->with(['JPX seven days' => ['jpx_tse', 168], 'SEC one day' => ['sec_exchange', 24]]);

test('UC-016 市場からの調査候補発見・確認は確認済み別名を使い取消と再確認で照合世代を追記する', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Listed Parent Corporation', 'SYNP', 'Nasdaq']]));
    expect(uc016AutomationDiscover())->toBe(0);
    $entry = DB::table('research_listing_catalog_entries')->sole();
    $aliasId = uc016CatalogAlias($entry->id, 'Synthetic Medical Research Co.');
    expect(uc016AutomationDiscover())->toBe(0);
    $firstMatch = uc016CatalogActiveMatches()->sole();
    expect($firstMatch->match_method)->toBe('verified_alias')->and($firstMatch->alias_id)->toBe($aliasId);
    DB::table('research_company_aliases')->where('id', $aliasId)->update(['revoked_at' => now()]);
    expect(uc016AutomationDiscover())->toBe(0);
    expect(uc016CatalogActiveMatches())->toHaveCount(0)
        ->and(DB::table('research_draft_listing_matches')->find($firstMatch->id)->superseded_at)->not->toBeNull();
    $replacementId = uc016CatalogAlias($entry->id, 'Synthetic Medical Research Co.');

    expect(uc016AutomationDiscover())->toBe(0);

    $newMatch = uc016CatalogActiveMatches()->sole();
    expect($newMatch->alias_id)->toBe($replacementId)->and($newMatch->generation)->toBeGreaterThan($firstMatch->generation);
    $this->assertDatabaseCount('research_entity_listings', 0);
});

test('UC-016 市場からの調査候補発見・確認は本人確認した親会社対応でも言及法人と親会社を同一法人へ変更しない', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Listed Parent Corporation', 'SYNP', 'NYSE']]));
    expect(uc016AutomationDiscover())->toBe(0);
    expect(uc016CatalogActiveMatches())->toHaveCount(0);
    $entry = DB::table('research_listing_catalog_entries')->sole();
    $aliasId = uc016CatalogAlias($entry->id, 'Synthetic Medical Research Co.', 'subsidiary_of');

    expect(uc016AutomationDiscover())->toBe(0);

    expect(uc016CatalogActiveMatches()->sole()->alias_id)->toBe($aliasId)
        ->and(DB::table('research_candidate_drafts')->sole()->raw_name)->toBe('Synthetic Medical Research Co.')
        ->and(DB::table('research_company_aliases')->find($aliasId)->relationship)->toBe('subsidiary_of');
    $this->assertDatabaseCount('research_entity_listings', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});

test('UC-016 市場からの調査候補発見・確認は別名先の発行体が現在版から消えたら古い別名を適用しない', function () {
    uc016CatalogConfig('sec_exchange');
    uc016AutomationSourceItem();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999001, 'Synthetic Listed Parent Corporation', 'SYNP', 'NYSE']]));
    expect(uc016AutomationDiscover())->toBe(0);
    $entry = DB::table('research_listing_catalog_entries')->sole();
    uc016CatalogAlias($entry->id, 'Synthetic Medical Research Co.');
    expect(uc016AutomationDiscover())->toBe(0);
    expect(uc016CatalogActiveMatches())->toHaveCount(1);
    $this->travel(25)->hours();
    uc016CatalogResponses('sec_exchange', uc016CatalogSec([[9999002, 'Synthetic Other Issuer', 'SYNO', 'Nasdaq']]));

    expect(uc016AutomationDiscover())->toBe(0);

    expect(uc016CatalogActiveMatches())->toHaveCount(0);
    $this->assertDatabaseCount('research_company_aliases', 1);
    $this->assertDatabaseCount('research_entity_listings', 0);
});
