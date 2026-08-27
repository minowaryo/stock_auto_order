<?php

namespace Tests\Feature;

use App\Actions\Signal\ShowSignalListAction;
use App\Livewire\Signal\SignalList;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-004: 利確検討画面（Livewireフルページ） — Red phase Livewire Component Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-004)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / signals)
|   - docs/product/mockups/screen-UC004-signal-list.html (STALE for scope:
|     the mockup shows TWO sections — 買い増し候補=UC-010 on top and
|     利確検討=UC-004 below, per ADR-0007. This cycle builds ONLY the
|     利確検討 section.)
|   - stock_auto_order-frontend-implementation-phase.md Phase 5
|
| Scope note (concurrent-session awareness): a second Claude Code session is
| (or was) working in this same working directory on F-010/UC-010
| (App\Models\BuySignal, App\Http\Controllers\BuySignalListController,
| tests/Feature/UC010BuySignalListTest.php, a GET /api/buy-signals route).
| This file deliberately does NOT reference, import, or depend on any of
| those files/routes/models. The screen under test
| (App\Livewire\Signal\SignalList) is defined here to use ONLY
| App\Actions\Signal\ShowSignalListAction (existing Holding/HoldingSnapshot/
| Signal data) — no BuySignal data is displayed in this cycle. The
| UC-010（買い増し候補）section of the mockup is intentionally omitted (not
| stubbed/placeholder'd) and will be added in a future cycle once the other
| session's F-010 work is mergeable (see PLAN.md).
|
| App\Livewire\Signal\SignalList does not exist yet (no class, no route, no
| Blade view). Every Livewire::test(SignalList::class) call below is
| expected to fail with a "class not found" style fatal error, and the plain
| HTTP guest test is expected to fail because GET /signals is not yet a
| registered route (only GET /api/signals exists per Phase 0's route split).
| That is the intended Red state, not a typo/setup bug.
|
| *** Backend gap this cycle must test-drive (Green-phase TODO) ***
| App\Actions\Signal\ShowSignalListAction::execute() currently does NOT
| return a stable `id` (holdings.id) per row. The screen needs this to link
| each row to /holdings/{id} (Phase 4, already Green). This mirrors the
| exact same gap Phase 0 fixed in ListHoldingsAction (which also lacked
| `id` before Phase 0 added it). The "銘柄詳細へのリンク" describe block below
| calls ShowSignalListAction::execute() directly (bypassing the Livewire
| component) and asserts $rows[0]['id'] === the seeded holding's real id —
| this assertion will fail today (KeyError / missing array key) until Green
| adds `'id' => $holding->id` to ShowSignalListAction::toRow(). Do NOT
| implement this in this Red-phase pass; it is intentionally left failing
| for Gate 4 review.
|
| This file reuses App\Actions\Signal\ShowSignalListAction unmodified (pure
| read, no side effects, safe to call fresh on every render() — same
| convention as HoldingList's ListHoldingsAction/ShowMarketIndicatorAction
| calls). Fixture-building helpers below are a fresh copy (unique
| `signalListTest` prefix, same convention as `holdingListTest*` in
| tests/Feature/HoldingListTest.php and `ucFrom004Test*` in
| tests/Feature/UC004SignalListTest.php) to avoid cross-file function
| redeclaration errors while keeping fixture shapes consistent with the
| already-Green JSON API test.
|
| NISA区分除外・含み益20%以下除外は ShowSignalListAction 側で既に
| tests/Feature/UC004SignalListTest.php によって検証済みのロジックのため、
| このファイルでは再テストしない（画面側は「Actionが返したものをそのまま表示
| する」ことだけを確認する — 30-testing.md CRUD網羅ルール／このタスクの指示に
| 従う）。
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag at Gate 4 review if a different contract is
| preferred):
|   - Route: GET /signals, `auth` middleware, Livewire full-page component,
|     added to the same `Route::middleware('auth')->group(...)` block as
|     /csv-import, /holdings, /holdings/{holding}, /import-batches/... in
|     routes/web.php (Green-phase task).
|   - render() calls ShowSignalListAction fresh every time (no
|     mount()-only caching), same "call on every render" convention as
|     HoldingList.
|   - signal_types badges render the raw signal_type string as-is (e.g.
|     "rsi_reversal"), not a translated/localized label — per the task's own
|     "your call, but don't invent business logic beyond display" guidance,
|     this file picks the simplest option (raw string) and asserts on it.
|     Flag at Gate 4 if a Japanese label mapping is preferred instead.
|   - split_limit_suggestion's 3rd tier (price === null, trend-following
|     remainder) is assumed to render the literal text "現在値以降" (chosen
|     over a bare "-" as more informative, consistent with the reason
|     summary's plain-Japanese style elsewhere on this screen). This is an
|     unconfirmed Green-phase contract — flag at Gate 4 if a different
|     placeholder (e.g. "-") is preferred. Either way, the key invariant
|     under test is: some non-empty, non-"null" text is shown for the null
|     price tier.
|   - Empty state message: "利確検討が必要な銘柄はありません" (given directly
|     in the task instructions), rendered via <x-empty-state>, mirroring
|     HoldingList's own empty-state pattern for /holdings.
|   - Unauthenticated access redirects to /login (302), same convention as
|     HoldingList/HoldingDetail (this is a Livewire full-page screen behind
|     the `auth` middleware, not the JSON API sibling endpoint which
|     tolerates 302/401/403).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function signalListTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
{
    $snapshottedAt ??= now();

    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => $snapshottedAt,
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $snapshottedAt,
    ]);

    return [$batch, $snapshot];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function signalListTestHolding(array $attributes = []): Holding
{
    return Holding::create(array_merge([
        'symbol_code' => '7203',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'トヨタ自動車',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function signalListTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 300,
        'average_cost' => 1000,
        'current_price' => 1300,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 90000,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function signalListTestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

describe('UC-004: 利確検討画面（Livewire）', function () {
    describe('正常系: 利確検討一覧表示', function () {
        test('含み益+20%超・シグナルありの銘柄が一覧表示される（銘柄情報/含み益率/シグナルバッジ/理由サマリ/分割指値3段）', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
            ]);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300, 'average_cost' => 1000.00, 'current_price' => 1300.00, 'unrealized_gain_rate' => 30.0,
            ]);
            signalListTestSignal($holdingSnapshot, [
                'signal_type' => 'rsi_reversal',
                'reason_summary' => 'RSIが72から65に反落',
            ]);

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('トヨタ自動車');
            $component->assertSee('7203');

            $html = $component->html();
            expect($html)->toContain('30'); // unrealized_gain_rate
            expect($html)->toContain('rsi_reversal'); // signal badge (raw signal_type)
            expect($html)->toContain('RSIが72から65に反落'); // signal_reason_summary

            // 分割指値3段: +20%地点(1200)・+35%地点(1350)の価格が表示される。
            expect($html)->toContain('1200');
            expect($html)->toContain('1350');
        });

        test('含み益+20%超・シグナルなしの銘柄も一覧に含まれ、理由サマリがActionの「シグナル未検出」文言のまま表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '6758', 'market' => 'jp', 'symbol_name' => 'ソニーグループ']);
            signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 50, 'average_cost' => 10000.00, 'current_price' => 13000.00, 'unrealized_gain_rate' => 30.0,
            ]);
            // Signal行を意図的に作らない。

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('ソニーグループ');
            // ShowSignalListAction::toRow()がシグナル0件時に返す文言そのまま
            // （画面側はActionの出力を再解釈しない）。
            $component->assertSee('利確検討が必要なシグナルは検出されていません');
        });

        test('複数シグナルが発生している場合、signal_typesの各バッジが表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100, 'average_cost' => 5000.00, 'current_price' => 7000.00, 'unrealized_gain_rate' => 40.0,
            ]);
            signalListTestSignal($holdingSnapshot, ['signal_type' => 'rsi_reversal', 'reason_summary' => 'RSIが72から65に反落']);
            signalListTestSignal($holdingSnapshot, ['signal_type' => 'macd_dead_cross', 'reason_summary' => 'MACDがデッドクロス']);

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $html = $component->html();
            expect($html)->toContain('rsi_reversal');
            expect($html)->toContain('macd_dead_cross');
            expect($html)->toContain('RSIが72から65に反落');
            expect($html)->toContain('MACDがデッドクロス');
        });
    });

    describe('分割指値の提案（3段階）', function () {
        test('トレンド追従枠（3段目、price=null）は空欄や"null"ではなく明示的な文言で表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300, 'average_cost' => 1000.00, 'current_price' => 1300.00, 'unrealized_gain_rate' => 30.0,
            ]);
            // Signal行は無し（トレンド追従枠の表示のみを確認するテストのため不要）。

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $html = $component->html();

            // price=nullのトレンド追従枠は明示的な文言（「現在値以降」を想定。
            // Gate4で別表現に変わってもよいが、空欄/リテラル"null"は不可）になる。
            expect($html)->toContain('現在値以降');
            expect($html)->not->toContain('>null<');
        });
    });

    describe('銘柄詳細へのリンク（Green phase TODO: ShowSignalListAction への id 追加が必要）', function () {
        test('ShowSignalListAction::execute()の各行にholdings.idと一致するidフィールドが含まれる', function () {
            [, $snapshot] = signalListTestImportBatch();
            $holding = signalListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            signalListTestHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 30.0]);

            $rows = app(ShowSignalListAction::class)->execute();

            expect($rows)->toHaveCount(1);
            // ShowSignalListAction::toRow()は現状`id`キーを返さないため、この
            // アサーションはGreenフェーズで`'id' => $holding->id`を追加するまで
            // 失敗する想定（Phase 0のListHoldingsAction先例と同じ回帰追加）。
            expect($rows[0]['id'])->toBe($holding->id);
        });

        test('一覧の各行が/holdings/{id}へのリンクを持つ', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            signalListTestHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 30.0]);

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSeeHtml("href=\"/holdings/{$holding->id}\"");
        });
    });

    describe('空状態', function () {
        test('対象銘柄が1件も存在しない場合、エラーにならず空状態メッセージが表示される', function () {
            $user = User::factory()->create();

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('利確検討が必要な銘柄はありません');
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/signalsにアクセスできない', function () {
            $this->get('/signals')->assertRedirect('/login');
        });
    });
});
