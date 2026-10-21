<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ResearchAutomationFixtures.php';

/** UC-016 / ADR-0033 R1,R4,R8: public commands, real MySQL, synthetic HTTP only. */
beforeEach(function () {
    uc016AutomationConfig();
    $this->travelTo(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    uc016AutomationFakeHttp();
});

test('UC-016 市場からの調査候補発見・確認は起動役で価格追跡と一覧確認の完了後に候補生成を直列実行する', function () {
    uc016AutomationAssertCommand();
    $events = [];
    uc016AutomationRegisterStartupChild('price:track', function () use (&$events) {
        $events[] = 'price-started';
        $events[] = 'price-finished';

        return 0;
    });
    uc016AutomationRegisterStartupChild('research:check-sources {--force}', function () use (&$events) {
        $events[] = 'check-started';
        $events[] = 'check-finished';

        return 0;
    });
    uc016AutomationRegisterStartupChild('research:discover-candidates', function () use (&$events) {
        $events[] = 'discover-started';
        $events[] = 'discover-finished';

        return 0;
    });

    $this->artisan('app:on-start')->assertSuccessful();

    expect($events)->toBe([
        'price-started', 'price-finished', 'check-started', 'check-finished',
        'discover-started', 'discover-finished',
    ]);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は画面の今すぐ更新だけでFDA取得後の企業下書きまで作成する', function () {
    uc016AutomationAssertCommand();

    Livewire::actingAs(User::factory()->create())->test(CandidateResearch::class)
        ->call('checkSources')->assertHasNoErrors();

    $this->assertDatabaseCount('research_source_items', 1);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    expect(DB::table('research_candidate_drafts')->sole()->raw_name)->toBe('Synthetic Medical Research Co.')
        ->and(DB::table('research_source_checks')->sole()->automation_payload)->toBeNull();
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDA取得値を後続用入力へ記録し候補作成を取得処理から分離する', function () {
    uc016AutomationAssertCommand();

    $this->artisan('research:check-sources')->assertSuccessful();

    $check = DB::table('research_source_checks')->sole();
    expect($check->automation_cursor)->toBe(0)->and($check->automation_payload)->not->toBeNull();
    $payload = json_decode($check->automation_payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['schema_version'])->toBe(1)
        ->and($check->automation_payload)->toContain('Synthetic Medical Research Co.')->toContain('Synthetic Imaging Assistant');
    $this->assertDatabaseCount('research_candidate_drafts', 0);
    $this->assertDatabaseCount('research_candidates', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDAの各申請形式から原法人製品と六フィールドを出典付きで抽出する', function (string $submission) {
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow($submission, ['Company' => 'Synthetic Device Laboratory Ltd.', 'Device' => 'Synthetic Diagnostic Instrument']),
        ])),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $item = DB::table('research_source_items')->sole();
    $document = DB::table('research_source_documents')->sole();
    $version = DB::table('research_document_versions')->sole();
    $draft = DB::table('research_candidate_drafts')->sole();
    $payload = json_decode($version->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($document->source_item_id)->toBe($item->id)->and($version->document_id)->toBe($document->id)
        ->and($version->phase)->toBe('feed')->and($draft->document_id)->toBe($document->id)
        ->and($draft->raw_name)->toBe('Synthetic Device Laboratory Ltd.')
        ->and($payload['schema_version'])->toBe(1)->and($payload['source_url'])->toBe($item->url)
        ->and($payload['source_fields'])->toEqualCanonicalizing([
            'company' => 'Synthetic Device Laboratory Ltd.', 'device_name' => 'Synthetic Diagnostic Instrument',
            'submission_number' => $submission, 'date_of_final_decision' => '2026-06-29',
            'review_panel' => 'Radiology', 'product_code' => 'QAS',
        ]);
    expect($version->parser_version)->not->toBeEmpty()->and($version->rule_version)->not->toBeEmpty();
    Http::assertSentCount(1);
})->with(['510k' => ['K260001'], 'De Novo' => ['DEN260001'], 'PMA supplement' => ['P260001/S001']]);

test('UC-016 市場からの調査候補発見・確認はFDA初回を基準線にし決定日を公開日や本人確認日に代入しない', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $draft = DB::table('research_candidate_drafts')->sole();
    $version = DB::table('research_document_versions')->sole();
    $payload = json_decode($version->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($draft->discovery_kind)->toBe('baseline')->and($draft->disposition)->toBe('unreviewed')
        ->and($draft->reviewed_on)->toBeNull()->and($draft->reviewed_by)->toBeNull()->and($draft->reviewed_version_id)->toBeNull()
        ->and($payload['published_at'])->toBeNull()->and($payload['announced_on'])->toBeNull()
        ->and($payload['source_fields']['date_of_final_decision'])->toBe('2026-06-29');
    expect(DB::table('research_candidate_drafts')->where('discovery_kind', 'new')->count())->toBe(0);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('holdings', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
    $this->assertDatabaseCount('research_entity_listings', 0);
});

test('UC-016 市場からの調査候補発見・確認は医療AI分類を一覧掲載という根拠で付け上場承認売上を推測しない', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $draft = DB::table('research_candidate_drafts')->sole();
    $fields = json_decode($draft->extracted_fields, true, flags: JSON_THROW_ON_ERROR);
    expect($fields['themes'])->toContain('医療AI')->and($fields['roles'])->toContain('一覧掲載企業');
    expect($draft->extracted_fields)->toContain('FDA')->toContain('一覧')
        ->not->toContain('売上確定')->not->toContain('上場確認済み')->not->toContain('承認済み');
    expect($draft->imported_candidate_id)->toBeNull()->and($draft->human_overrides)->toBeNull();
    $this->assertDatabaseCount('research_draft_listing_matches', 0);
});

test('UC-016 市場からの調査候補発見・確認は後続で増えたFDA資料だけを新規検出にし原発表日不明を維持する', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);
    $this->travel(8)->days();
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001'), uc016FdaRow('K260002', ['Company' => 'Synthetic Second Laboratory Ltd.']),
        ])),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    expect(DB::table('research_candidate_drafts')->where('discovery_kind', 'baseline')->count())->toBe(1)
        ->and(DB::table('research_candidate_drafts')->where('discovery_kind', 'new')->count())->toBe(1);
    $new = DB::table('research_candidate_drafts')->where('discovery_kind', 'new')->sole();
    $payload = json_decode(DB::table('research_document_versions')->find($new->latest_version_id)->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($new->first_detected_at)->toBe(now()->toDateTimeString())->and($payload['announced_on'])->toBeNull();
});

test('UC-016 市場からの調査候補発見・確認は導入前FDA原情報を後処理し新着と本人確認を捏造しない', function () {
    $itemId = uc016AutomationSourceItem();
    $original = DB::table('research_source_items')->find($itemId);

    expect(uc016AutomationDiscover())->toBe(0);

    $draft = DB::table('research_candidate_drafts')->sole();
    expect($draft->discovery_kind)->toBe('backfill')->and($draft->reviewed_on)->toBeNull()
        ->and($draft->first_detected_at)->toBe(now()->toDateTimeString())
        ->and(DB::table('research_source_items')->find($itemId))->toEqual($original);
    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は既に見送った取得項目を後処理で未確認へ復活させない', function () {
    $itemId = uc016AutomationSourceItem(['disposition' => 'ignored', 'reviewed_at' => now()->subDay()]);
    $original = DB::table('research_source_items')->find($itemId);

    expect(uc016AutomationDiscover())->toBe(0);

    expect(DB::table('research_candidate_drafts')->where('disposition', 'unreviewed')->count())->toBe(0)
        ->and(DB::table('research_source_items')->find($itemId))->toEqual($original);
    $this->assertDatabaseCount('research_candidates', 0);
});

test('UC-016 市場からの調査候補発見・確認は同じFDA入力を再観察しても資料版下書き履歴とロック版を増やさない', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);
    $draft = DB::table('research_candidate_drafts')->sole();
    $revisionCount = DB::table('research_draft_revisions')->count();
    $this->travel(8)->days();
    uc016AutomationFakeHttp();

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $this->assertDatabaseCount('research_document_versions', 1);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $this->assertDatabaseCount('research_draft_revisions', $revisionCount);
    expect(DB::table('research_candidate_drafts')->sole()->lock_version)->toBe($draft->lock_version);
});

test('UC-016 市場からの調査候補発見・確認は同URLの製品訂正を新資料版へ保存し初回取得項目を上書きしない', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);
    $original = DB::table('research_source_items')->sole();
    $firstVersion = DB::table('research_document_versions')->sole();
    $this->travel(8)->days();
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001', ['Device' => 'Synthetic Corrected Imaging Assistant']),
        ])),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $this->assertDatabaseCount('research_document_versions', 2);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
    $latest = DB::table('research_document_versions')->latest('version_no')->first();
    $payload = json_decode($latest->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['source_fields']['device_name'])->toBe('Synthetic Corrected Imaging Assistant')
        ->and(DB::table('research_document_versions')->find($firstVersion->id))->toEqual($firstVersion)
        ->and(DB::table('research_source_items')->sole())->toEqual($original);
});

test('UC-016 市場からの調査候補発見・確認はFDA訂正AからBからAを三つの追記版として残す', function () {
    foreach (['Synthetic Imaging Assistant', 'Synthetic Corrected Imaging Assistant', 'Synthetic Imaging Assistant'] as $device) {
        uc016AutomationFakeHttp([
            'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
                uc016FdaRow('K260001', ['Device' => $device]),
            ])),
        ]);
        expect(uc016AutomationCheckAndDiscover())->toBe(0);
        $this->travel(8)->days();
    }

    $versions = DB::table('research_document_versions')->orderBy('version_no')->get();
    expect($versions)->toHaveCount(3)->and($versions->pluck('version_no')->all())->toBe([1, 2, 3])
        ->and($versions[0]->payload_hash)->toBe($versions[2]->payload_hash)
        ->and($versions[0]->payload_hash)->not->toBe($versions[1]->payload_hash);
    $this->assertDatabaseCount('research_candidate_drafts', 1);
});

test('UC-016 市場からの調査候補発見・確認は法人名訂正で旧下書きの本人訂正と見送りを保持し消失を別状態にする', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);
    $old = DB::table('research_candidate_drafts')->sole();
    $overrides = json_encode(['raw_name' => '本人が訂正した表示名'], JSON_THROW_ON_ERROR);
    DB::table('research_candidate_drafts')->where('id', $old->id)->update([
        'disposition' => 'rejected', 'reason' => '本人の調査対象ではない', 'human_overrides' => $overrides,
    ]);
    $this->travel(8)->days();
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001', ['Company' => 'Synthetic Replacement Laboratory Ltd.']),
        ])),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $removed = DB::table('research_candidate_drafts')->find($old->id);
    expect($removed->presence)->toBe('removed')->and($removed->disposition)->toBe('rejected')
        ->and($removed->reason)->toBe('本人の調査対象ではない')
        ->and(json_decode($removed->human_overrides, true, flags: JSON_THROW_ON_ERROR))->toBe(['raw_name' => '本人が訂正した表示名'])
        ->and($removed->latest_version_id)->toBe($old->latest_version_id)
        ->and($removed->current_document_version_id)->not->toBe($old->current_document_version_id);
    $this->assertDatabaseCount('research_candidate_drafts', 2);
    expect(DB::table('research_candidate_drafts')->where('presence', 'present')->sole()->raw_name)
        ->toBe('Synthetic Replacement Laboratory Ltd.');
});

test('UC-016 市場からの調査候補発見・確認は同一企業の複数機器を別資料として保持し一意制約で証拠を落とさない', function () {
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001'), uc016FdaRow('K260002', ['Device' => 'Synthetic Second Device']),
        ])),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $drafts = DB::table('research_candidate_drafts')->get();
    expect($drafts)->toHaveCount(2)->and($drafts->pluck('document_id')->unique())->toHaveCount(2)
        ->and($drafts->pluck('mention_key')->unique())->toHaveCount(1);
    $this->assertDatabaseCount('research_source_documents', 2);
    $this->assertDatabaseCount('research_candidates', 0);
});

test('UC-016 市場からの調査候補発見・確認はNFKCと連続空白だけを整理し法人格を消さない厳密キーを保存する', function () {
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001', ['Company' => 'Ｓｙｎｔｈｅｔｉｃ　　Lab. Ltd.']),
        ])),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $draft = DB::table('research_candidate_drafts')->sole();
    expect($draft->raw_name)->toBe('Ｓｙｎｔｈｅｔｉｃ　　Lab. Ltd.')
        ->and($draft->normalized_name)->toBe('Synthetic Lab. Ltd.')
        ->and($draft->mention_key)->toBe(hash('sha256', 'Synthetic Lab. Ltd.'));
});

test('UC-016 市場からの調査候補発見・確認は201件を200件と残り1件に分け中断カーソルを保存して再開する', function () {
    $rows = [];
    for ($index = 1; $index <= 201; $index++) {
        $rows[] = uc016FdaRow(sprintf('K26%04d', $index), ['Company' => 'Synthetic Laboratory '.$index.' Ltd.']);
    }
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv($rows)),
    ]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $check = DB::table('research_source_checks')->sole();
    expect($check->automation_cursor)->toBe(200)->and($check->automation_payload)->not->toBeNull();
    $this->assertDatabaseCount('research_candidate_drafts', 200);
    expect(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('partial');

    expect(uc016AutomationDiscover())->toBe(0);

    $this->assertDatabaseCount('research_candidate_drafts', 201);
    $this->assertDatabaseCount('research_document_versions', 201);
    $finishedCheck = DB::table('research_source_checks')->find($check->id);
    expect($finishedCheck->automation_payload)->toBeNull()->and($finishedCheck->automation_cursor)->toBeNull()
        ->and(DB::table('research_automation_runs')->latest('id')->first()->status)->toBe('completed');
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は未処理の観察AとBを古い取得順に消化し後続取得で入力を上書きしない', function () {
    uc016AutomationAssertCommand();
    $this->artisan('research:check-sources')->assertSuccessful();
    $first = DB::table('research_source_checks')->sole();
    $this->travel(8)->days();
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001', ['Device' => 'Synthetic Updated Device']),
        ])),
    ]);
    $this->artisan('research:check-sources')->assertSuccessful();
    expect(DB::table('research_source_checks')->find($first->id)->automation_payload)->toBe($first->automation_payload);

    expect(uc016AutomationDiscover())->toBe(0);

    $versions = DB::table('research_document_versions')->orderBy('version_no')->get();
    expect($versions)->toHaveCount(2);
    $payloads = $versions->map(fn ($version) => json_decode($version->payload, true, flags: JSON_THROW_ON_ERROR));
    expect($payloads[0]['source_fields']['device_name'])->toBe('Synthetic Imaging Assistant')
        ->and($payloads[1]['source_fields']['device_name'])->toBe('Synthetic Updated Device');
});

test('UC-016 市場からの調査候補発見・確認は抽出停止中に新しい一時入力を作らず保存済み未処理入力も消さない', function () {
    uc016AutomationAssertCommand();
    $this->artisan('research:check-sources')->assertSuccessful();
    $first = DB::table('research_source_checks')->sole();
    config(['research_automation.enabled' => false]);
    $this->travel(8)->days();
    uc016AutomationFakeHttp();
    $this->artisan('research:check-sources')->assertSuccessful();

    expect(uc016AutomationDiscover())->toBe(0);

    expect(DB::table('research_source_checks')->find($first->id)->automation_payload)->toBe($first->automation_payload)
        ->and(DB::table('research_source_checks')->latest('id')->first()->automation_payload)->toBeNull();
    $this->assertDatabaseCount('research_candidate_drafts', 0);
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認はFDA個別抽出停止でも取得と手動記録用項目を維持する', function () {
    config(['research_automation.sources.S10.enabled' => false]);

    expect(uc016AutomationCheckAndDiscover())->toBe(0);

    $this->assertDatabaseCount('research_source_items', 1);
    $this->assertDatabaseCount('research_candidate_drafts', 0);
    expect(DB::table('research_source_checks')->sole()->automation_payload)->toBeNull();
    Http::assertSentCount(1);
});

test('UC-016 市場からの調査候補発見・確認は取得共有ロック使用中に候補実行やHTTP試行を作らない', function () {
    uc016AutomationAssertCommand();
    uc016AutomationSourceItem();
    $lock = Cache::store('database')->lock('research-source-check', 600);
    expect($lock->get())->toBeTrue();
    try {
        expect(uc016AutomationDiscover())->toBe(0);
        $this->assertDatabaseCount('research_automation_runs', 0);
        $this->assertDatabaseCount('research_document_attempts', 0);
        $this->assertDatabaseCount('research_candidate_drafts', 0);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('UC-016 市場からの調査候補発見・確認は抽出上限超過を一部成功にせず資料下書きとカーソルをまとめて戻す', function () {
    uc016AutomationAssertCommand();
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(uc016AutomationFdaCsv([
            uc016FdaRow('K260001'), uc016FdaRow('K260002', ['Company' => str_repeat('X', 256)]),
        ])),
    ]);
    $this->artisan('research:check-sources')->assertSuccessful();
    $check = DB::table('research_source_checks')->sole();

    expect(uc016AutomationDiscover())->not->toBe(0);

    $this->assertDatabaseCount('research_candidate_drafts', 0);
    $this->assertDatabaseCount('research_document_versions', 0);
    $this->assertDatabaseCount('research_draft_revisions', 0);
    $retained = DB::table('research_source_checks')->find($check->id);
    expect($retained->automation_cursor)->toBe(0)->and($retained->automation_payload)->toBe($check->automation_payload);
    $run = DB::table('research_automation_runs')->latest('id')->first();
    expect($run->status)->toBe('failed')->and($run->error_code)->not->toBeNull()
        ->and($run->error_code)->not->toContain(str_repeat('X', 50));
    $this->assertDatabaseCount('research_source_items', 2);
});

test('UC-016 市場からの調査候補発見・確認は破損FDA応答を企業0件成功にせず以前の成功資料と本人記録を維持する', function () {
    expect(uc016AutomationCheckAndDiscover())->toBe(0);
    $draft = DB::table('research_candidate_drafts')->sole();
    $version = DB::table('research_document_versions')->sole();
    $this->travel(8)->days();
    uc016AutomationFakeHttp([
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response('PRIVATE_RESPONSE_MARKER,malformed'),
    ]);

    expect(Artisan::call('research:check-sources'))->not->toBe(0);
    expect(uc016AutomationDiscover())->toBe(0);

    $failed = DB::table('research_source_checks')->latest('id')->first();
    expect($failed->result)->toBe('failed')->and($failed->automation_payload)->toBeNull()
        ->and($failed->error_message)->not->toContain('PRIVATE_RESPONSE_MARKER')
        ->and(DB::table('research_candidate_drafts')->sole()->lock_version)->toBe($draft->lock_version)
        ->and(DB::table('research_document_versions')->sole())->toEqual($version);
    $this->assertDatabaseCount('research_candidates', 0);
    $this->assertDatabaseCount('watchlist_items', 0);
});
