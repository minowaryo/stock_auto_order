<?php

namespace Tests\Feature;

use App\Actions\Watchlist\ImportFavoriteCsvAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Livewire\Candidate\CandidateCheck;
use App\Livewire\Candidate\CandidateResearch;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Models\WatchRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use Psr\Log\LoggerInterface;

/**
 * UC-016「市場からの調査候補発見・確認」監視への受け渡し、UC-012 回帰の Red テスト。
 *
 * Gate 4 で確認する UI 契約: CandidateResearch::prepareHandoff($candidateId) は
 * 確認欄に候補の現行版と冪等キーを保持し、confirmHandoff($reason) はその版を
 * 再照合して確定する。失敗時は handoff エラーを表示し、成功表示をしない。
 * テストデータは公開資料を取得せず、本人が確認した事実を模して DB に置く。
 */
function uc016HandoffCandidate(array $overrides = []): array
{
    $now = now();
    $eventId = DB::table('research_events')->insertGetId([
        'title' => '設備投資の発表',
        'original_url' => 'https://example.org/press/robotics-2026',
        'original_publisher' => '発表主体',
        'announced_on' => array_key_exists('announced_on', $overrides) ? $overrides['announced_on'] : '2026-09-30',
        'verification_status' => 'verified',
        'verified_on' => '2026-10-04',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $entityId = DB::table('research_entities')->insertGetId([
        'legal_name' => '上場ロボット株式会社',
        'identification_status' => $overrides['identification_status'] ?? 'identified',
        'identification_note' => '上場主体の公表資料で確認',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $listingId = DB::table('research_entity_listings')->insertGetId([
        'research_entity_id' => $entityId,
        'market' => 'jp',
        'symbol_code' => $overrides['symbol_code'] ?? '8888',
        'listed_entity_name' => '上場ロボット株式会社',
        'source_url' => 'https://example.org/company/listing',
        'confirmed_on' => '2026-10-04',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $candidateId = DB::table('research_candidates')->insertGetId([
        'research_event_id' => $eventId,
        'research_entity_id' => $entityId,
        'theme' => 'フィジカルAI',
        'theme_relation' => '産業用ロボット設備を供給する',
        'entity_role' => '供給者',
        'evidence_stage' => 'product',
        'counter_evidence' => '売上への寄与額は未確認',
        'counter_evidence_checked_on' => array_key_exists('counter_evidence_checked_on', $overrides)
            ? $overrides['counter_evidence_checked_on'] : '2026-10-04',
        'status' => 'investigating',
        'lock_version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('research_claims')->insert([
        'research_candidate_id' => $candidateId,
        'claim' => '設備の供給を発表した',
        'evidence_url' => 'https://example.org/press/robotics-2026',
        'status' => $overrides['claim_status'] ?? 'verified',
        'is_primary_source' => $overrides['is_primary_source'] ?? true,
        'checked_on' => '2026-10-04',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $revisionId = DB::table('research_candidate_revisions')->insertGetId([
        'research_candidate_id' => $candidateId,
        'lock_version' => 1,
        'change_type' => 'created',
        'payload' => json_encode(['event_id' => $eventId, 'entity_id' => $entityId], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return compact('candidateId', 'revisionId', 'listingId');
}

function uc016HandoffBaseline(): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp.csv',
        'us_stock_filename' => 'us.csv',
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $other = Holding::create([
        'symbol_code' => '9999', 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '照合用別銘柄', 'first_detected_at' => now(),
    ]);
    WatchlistItem::create([
        'holding_id' => $other->id,
        'source' => 'rakuten_favorites_csv',
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);

    return $snapshot;
}

function uc016HandoffHolding(string $code = '8888'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '上場ロボット株式会社', 'first_detected_at' => now(),
    ]);
}

function uc016HandoffFavoriteFile(string $code, string $folder): UploadedFile
{
    $csv = '"MS2","2"'."\r\n".'"STK","'.$code.'","'.$folder.'","1","東Ｐ","上場ロボット株式会社"'."\r\n";

    return UploadedFile::fake()->createWithContent('favorites.csv', mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'));
}

test('UC-016 市場からの調査候補発見・確認は一次資料の確認がない候補を監視登録しない', function () {
    $ids = uc016HandoffCandidate(['is_primary_source' => false]);
    uc016HandoffBaseline();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '監視を選択した')
        ->assertHasErrors(['handoff']);

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(0)
        ->and(WatchlistItem::count())->toBe(1)
        ->and(Holding::where('symbol_code', '8888')->exists())->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は上場主体の同定と反証確認が欠ける候補を監視登録しない', function () {
    $ids = uc016HandoffCandidate(['identification_status' => 'ambiguous', 'counter_evidence_checked_on' => null]);
    uc016HandoffBaseline();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '監視を選択した')
        ->assertHasErrors(['handoff']);

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(0)
        ->and(Holding::where('symbol_code', '8888')->exists())->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は元発表日が不明な候補を監視登録しない', function () {
    $ids = uc016HandoffCandidate(['announced_on' => null]);
    uc016HandoffBaseline();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '監視を選択した')
        ->assertHasErrors(['handoff']);

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(0)
        ->and(Holding::where('symbol_code', '8888')->exists())->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は照合元がない候補を未登録と断定せず監視登録しない', function () {
    $ids = uc016HandoffCandidate();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '監視を選択した')
        ->assertHasErrors(['handoff']);

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(0)
        ->and(WatchlistItem::count())->toBe(0)
        ->and(Holding::count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は本人の確認後に未保有未登録銘柄を星付きで監視へ渡す', function () {
    $ids = uc016HandoffCandidate();
    $snapshot = uc016HandoffBaseline();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->assertSet('handoffCandidateId', $ids['candidateId'])
        ->call('confirmHandoff', '一次資料と上場主体を確認し監視を選択した')
        ->assertHasNoErrors();

    $holding = Holding::where('symbol_code', '8888')->sole();
    $item = WatchlistItem::where('holding_id', $holding->id)->sole();
    expect($item->is_starred)->toBeTrue()
        ->and($item->source)->toBe('manual')
        ->and($item->folder_name)->toBeNull()
        ->and($item->last_seen_in_csv_at)->toBeNull();
    $handoff = DB::table('research_watchlist_handoffs')->sole();
    expect($handoff->outcome)->toBe('created')
        ->and($handoff->research_candidate_revision_id)->toBe($ids['revisionId'])
        ->and($handoff->research_entity_listing_id)->toBe($ids['listingId'])
        ->and($handoff->watchlist_item_id)->toBe($item->id)
        ->and($handoff->matched_snapshot_id)->toBe($snapshot->id)
        ->and($handoff->favorites_last_imported_at)->not->toBeNull();

    $row = collect(app(ShowWatchlistAction::class)->execute())->firstWhere('symbol_code', '8888');
    expect($row['registration_routes'])->toContain('調査候補')
        ->and($row['in_rakuten_favorites'])->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は監視への受け渡しをauditチャンネルに最小スキーマで記録する', function () {
    $ids = uc016HandoffCandidate();
    uc016HandoffBaseline();
    $user = User::factory()->create();
    $auditPayload = null;
    $auditLogger = Mockery::mock(LoggerInterface::class);
    $auditLogger->shouldReceive('info')->once()->withArgs(function ($message, $context) use (&$auditPayload) {
        $auditPayload = $context;

        return true;
    });
    Log::shouldReceive('channel')->with('audit')->once()->andReturn($auditLogger);

    Livewire::actingAs($user)
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '本人が監視を選択した')
        ->assertHasNoErrors();

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(1)
        ->and($auditPayload)->toBeArray();
    $this->assertEqualsCanonicalizing(
        ['action', 'actor_id', 'subject_type', 'subject_id'],
        array_keys($auditPayload),
    );
    expect($auditPayload['action'])->toBeString()->not->toBeEmpty()
        ->and($auditPayload['actor_id'])->toBe($user->id)
        ->and($auditPayload['subject_id'])->toBe($ids['candidateId']);
    $this->assertContains($auditPayload['subject_type'], ['research_candidates', 'App\\Models\\ResearchCandidate']);
});

test('UC-016 市場からの調査候補発見・確認は既存行の星とフォルダとメモを保持して関連付ける', function () {
    $ids = uc016HandoffCandidate();
    uc016HandoffBaseline();
    $holding = uc016HandoffHolding();
    $item = WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => '本人の既存フォルダ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => false,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);
    $memo = WatchRecord::create([
        'holding_id' => $holding->id,
        'watch_status' => '様子見',
        'memo' => '既存メモを残す',
        'recorded_at' => now(),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '既存候補に関連付ける')
        ->assertHasNoErrors();

    expect(WatchlistItem::where('holding_id', $holding->id)->count())->toBe(1)
        ->and($item->fresh()->is_starred)->toBeFalse()
        ->and($item->fresh()->folder_name)->toBe('本人の既存フォルダ')
        ->and(WatchRecord::find($memo->id)->memo)->toBe('既存メモを残す')
        ->and(DB::table('research_watchlist_handoffs')->sole()->outcome)->toBe('linked_existing')
        ->and(DB::table('research_candidates')->where('id', $ids['candidateId'])->value('status'))->toBe('investigating');
});

test('UC-016 市場からの調査候補発見・確認は確定時に保有済みへ変わった銘柄を新規登録しない', function () {
    $ids = uc016HandoffCandidate();
    $snapshot = uc016HandoffBaseline();
    $component = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId']);

    $holding = uc016HandoffHolding();
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 1000,
        'current_price' => 1200,
        'unrealized_gain_amount' => 2000,
        'unrealized_gain_rate' => 20,
    ]);

    $component->call('confirmHandoff', '監視を選択した')->assertHasNoErrors();

    $handoff = DB::table('research_watchlist_handoffs')->sole();
    expect($handoff->outcome)->toBe('already_held')
        ->and($handoff->holding_id)->toBe($holding->id)
        ->and($handoff->watchlist_item_id)->toBeNull()
        ->and(WatchlistItem::where('holding_id', $holding->id)->exists())->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は確認欄の後に版が変わると旧版で確定しない', function () {
    $ids = uc016HandoffCandidate();
    uc016HandoffBaseline();
    $component = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId']);

    DB::table('research_candidates')->where('id', $ids['candidateId'])->update(['lock_version' => 2]);

    $component->call('confirmHandoff', '旧版のまま監視を選択した')->assertHasErrors(['handoff']);
    expect(DB::table('research_watchlist_handoffs')->count())->toBe(0)
        ->and(Holding::where('symbol_code', '8888')->exists())->toBeFalse();
});

test('UC-016 市場からの調査候補発見・確認は確認操作を再送しても受け渡しを重複しない', function () {
    $ids = uc016HandoffCandidate();
    uc016HandoffBaseline();
    $component = Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId']);

    $component->call('confirmHandoff', '監視を選択した');
    $component->call('confirmHandoff', '監視を選択した');

    expect(DB::table('research_watchlist_handoffs')->count())->toBe(1)
        ->and(WatchlistItem::where('holding_id', Holding::where('symbol_code', '8888')->sole()->id)->count())->toBe(1);
});

test('UC-012 お気に入り未保有銘柄ウォッチリストは調査経由の行をCSV再取込で再利用し両経路を示す', function () {
    Bus::fake();
    $ids = uc016HandoffCandidate();
    uc016HandoffBaseline();
    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->call('prepareHandoff', $ids['candidateId'])
        ->call('confirmHandoff', '監視を選択した');
    $item = WatchlistItem::where('holding_id', Holding::where('symbol_code', '8888')->sole()->id)->sole();

    app(ImportFavoriteCsvAction::class)->execute(uc016HandoffFavoriteFile('8888', '楽天で後から追加'));

    expect(WatchlistItem::where('holding_id', $item->holding_id)->count())->toBe(1)
        ->and($item->fresh()->is_starred)->toBeTrue()
        ->and($item->fresh()->folder_name)->toBe('楽天で後から追加')
        ->and(DB::table('research_watchlist_handoffs')->where('watchlist_item_id', $item->id)->exists())->toBeTrue();
    $row = collect(app(ShowWatchlistAction::class)->execute())->firstWhere('symbol_code', '8888');
    expect($row['registration_routes'])->toContain('CSV', '調査候補')
        ->and($row['in_rakuten_favorites'])->toBeTrue();
});

test('UC-012 お気に入り未保有銘柄ウォッチリストの一括更新は調査中の候補を対象に含めない', function () {
    Bus::fake();
    uc016HandoffCandidate();
    uc016HandoffBaseline();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateCheck::class)
        ->call('refreshAll');

    expect(Holding::where('symbol_code', '8888')->exists())->toBeFalse()
        ->and(WatchlistItem::whereHas('holding', fn ($query) => $query->where('symbol_code', '8888'))->exists())->toBeFalse();
});
