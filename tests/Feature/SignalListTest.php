<?php

namespace Tests\Feature;

use App\Actions\Signal\ShowSignalListAction;
use App\Livewire\Signal\SignalList;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
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
| -------------------------------------------------------------------------
| CR (2026-08-29, CHG-0006): 利確検討ラインの動的分岐 — 既存テストへの影響確認
| -------------------------------------------------------------------------
| docs/product/use-cases.md UC-004業務ルール「利確検討ラインの動的分岐」の
| 動的分岐ロジック自体はtests/Unit/Services/Analysis/
| TakeProfitThresholdEvaluatorTest.php / tests/Feature/UC004SignalListTest.php
| 側で検証するため、このファイルでは再テストしない（上記と同じ方針）。
| 既存フィクスチャへの影響を確認した結果、調整は不要と判断した:
|   - 利確検討（signalListTest*）側のフィクスチャは、いずれも
|     FundamentalIndicatorレコードを作成していない
|     （signalListTestFundamentalIndicator()は買い増し候補/UC-010セクション
|     専用のヘルパーで、利確検討側のフィクスチャからは呼ばれていない）。
|     そのため利確検討側のholdingは常に財務健全性'unavailable'となり、高水準
|     モードの条件（シグナル0件 かつ 財務健全性'passed'）を満たし得ない。
|   - 買い増し候補（UC-010）側のフィクスチャはsignalListTestFundamentalIndicator()
|     （equity_ratio=58.0, roe=15.2, 成長率とも正値 → 'passed'相当）を使うが、
|     いずれもunrealized_gain_rateが負値（-8.5/-3.2/-12.0）であり、通常モード
|     （+20%超）・高水準モード（+150%超）のいずれの対象抽出条件も満たさない
|     ため、動的分岐ロジックの導入有無に関わらず利確検討一覧には現れない。
|   - 以上より、本ファイルの既存13テストケースは全て無調整のまま実行して
|     PASSすることを確認済み（新規テストケースの追加は不要）。
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

/*
|--------------------------------------------------------------------------
| UC-010 addendum (later cycle): 買い増し候補セクションの統合 — Red phase
|--------------------------------------------------------------------------
|
| The file-level docblock above predates UC-010's backend merge (F-010,
| App\Models\BuySignal, App\Actions\Signal\ShowBuySignalListAction,
| GET /api/buy-signals) and explicitly deferred the 買い増し候補（UC-010）
| section of docs/product/mockups/screen-UC004-signal-list.html to "a
| future cycle". That backend is now Green and merged to main
| (tests/Feature/UC010BuySignalListTest.php), so this cycle adds that
| deferred section to App\Livewire\Signal\SignalList /
| resources/views/livewire/signal/signal-list.blade.php per the mockup's
| two-section layout (買い増し候補 on top, 利確検討 below).
|
| Current state: SignalList::render() only calls ShowSignalListAction and
| the Blade view has no reference to buy-signal data at all. Every test
| below is expected to fail because the 買い増し候補 markup (symbol rows,
| signal badges, fundamental summary, split buy-down suggestion, empty
| state, section heading) simply does not exist in the rendered HTML yet —
| not because of a missing class/route (both already exist and are Green).
|
| Helper naming: `signalListTestBuySignal()` / `signalListTestFundamentalIndicator()`
| are new additions to this file's existing `signalListTest*` helper family
| (no collision with the `ucFrom010Test*` prefix used by
| tests/Feature/UC010BuySignalListTest.php, nor with this file's own
| existing `signalListTest*` helpers, which do not cover BuySignal/
| FundamentalIndicator yet).
|
| Assumptions made while writing these tests (flag at Gate 4 review if a
| different contract is preferred):
|   - render() also calls ShowBuySignalListAction fresh every time and
|     passes the result to the view as `buySignals` (same "call on every
|     render" convention already used for `signals`/ShowSignalListAction).
|   - buy_signal_types badges render the raw signal_type string as-is
|     (e.g. "rsi_oversold_rebound"), mirroring the existing 利確検討
|     section's raw signal_type badge convention (no translated/localized
|     label).
|   - fundamental_summary is displayed as the plain sentence
|     ShowBuySignalListAction already produces (e.g. "ROE15.2%・自己資本
|     比率58.0%・..."); this file only asserts substrings ("ROE", the
|     equity_ratio value), not exact wording.
|   - NISA推奨 is surfaced via some text containing "NISA" (mirroring
|     SectorDashboard's existing `<x-badge variant="info">NISA推奨</x-badge>`
|     convention) — this file does not pin the exact wording beyond that
|     substring.
|   - fundamental_status=unavailable is surfaced via text containing
|     "取得不可" (mirroring the mockup's `財務指標 取得不可` badge) — this
|     file does not pin exact wording beyond that substring.
|   - Empty state message for the 買い増し候補 section:
|     "買い増しを検討できる押し目銘柄はありません" (per use-cases.md UC-010
|     エラーケース and the mockup's commented-out empty-state block).
|   - Section headings distinguishing the two blocks contain the literal
|     strings "買い増し候補" and "利確検討" (per the mockup's `<h2>` text),
|     used here only to confirm both sections coexist on one screen.
|
*/

/**
 * Creates a `buy_signals` row (UC-010; App\Models\BuySignal — already
 * merged/Green, see tests/Feature/UC010BuySignalListTest.php).
 *
 * @param  array<string, mixed>  $attributes
 */
function signalListTestBuySignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): BuySignal
{
    return BuySignal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => 'RSIが28から34へ反発しました',
    ], $attributes));
}

/**
 * Creates a `technical_indicators` row (CHG-0007 判定チェックリスト).
 * Neutral defaults — individual tests override the fields they assert on.
 *
 * @param  array<string, mixed>  $attributes
 */
function signalListTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 50.0,
        'macd' => 0.0,
        'macd_signal' => 0.0,
        'ma20' => 1000.0,
        'ma75' => 1000.0,
        'bb_upper' => 1200.0,
        'bb_lower' => 800.0,
        'volume' => 1_000_000,
        'volume_ma20' => 1_000_000.0,
        'week52_high' => 1500.0,
        'week52_low' => 700.0,
        'relative_strength_vs_market' => 0.0,
        'relative_strength_vs_sector' => 0.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function signalListTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::updateOrCreate(
        ['holding_id' => $holding->id],
        array_merge([
            'per' => 15.0,
            'pbr' => 1.5,
            'roe' => 15.2,
            'revenue_growth' => 8.0,
            'operating_income_growth' => 12.3,
            'equity_ratio' => 58.0,
            'dividend_yield' => 2.0,
            'dividend_payout_ratio' => 30.0,
            'eps_growth' => 10.0,
            'peg_ratio' => 1.2,
            'fetched_at' => now(),
        ], $attributes),
    );
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
            // unrealized_gain_rate（符号付きパーセントルール: sprintf('%+.1f%%', $value)相当）
            expect($html)->toContain('+30.0%');
            expect($html)->toContain('rsi_reversal'); // signal badge (raw signal_type)
            expect($html)->toContain('RSIが72から65に反落'); // signal_reason_summary

            // 分割指値3段: +20%地点(1200)・+35%地点(1350)の価格が表示される
            // （価格ルール: number_format($value, 2)相当。カンマ区切り + 小数点2桁）。
            expect($html)->toContain('1,200.00');
            expect($html)->toContain('1,350.00');
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

        test('高水準モード適用銘柄（CHG-0006）の分割指値ラベルは+100%/+150%地点になり、+20%/+35%のラベルのままにはならない', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '4902', 'market' => 'jp', 'symbol_name' => '高水準銘柄']);
            signalListTestFundamentalIndicator($holding); // デフォルトで財務健全性passed相当の値。
            signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300, 'average_cost' => 1000.00, 'current_price' => 2600.00, 'unrealized_gain_rate' => 160.0,
            ]);
            // Signal行は無し（シグナル0件）。equity_ratio=58.0/roe=15.2/成長率プラスの
            // デフォルト値と合わせて高水準モード（+150%超・+100%/+150%地点）が適用される。

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $html = $component->html();

            // 高水準モードでは分割指値のラベル自体も+100%/+150%地点に読み替わるべきで、
            // 通常モードの+20%/+35%地点というラベルのまま残ってはならない
            // （signal_reason_summaryには「+150%まで引き上げ」等の文言が別途出るが、
            // 隣の価格ラベルが古いままだと内部矛盾した表示になってしまうため）。
            expect($html)->toContain('+100%地点');
            expect($html)->toContain('+150%地点');
            expect($html)->not->toContain('+20%地点');
            expect($html)->not->toContain('+35%地点');
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

describe('UC-010: 買い増し候補セクション（売買シグナル画面への統合）', function () {
    describe('正常系: 買い増し候補一覧表示', function () {
        test('押し目買いシグナルが1件以上あり財務健全性passedの銘柄が、買い増し候補セクションに表示される（銘柄名/含み益率/シグナルバッジ/理由サマリ/財務健全性サマリ/分割買い下がり3段階）', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding([
                'symbol_code' => '5201', 'market' => 'jp', 'symbol_name' => 'サンプル素材',
            ]);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300, 'average_cost' => 1200.00, 'current_price' => 1000.00, 'unrealized_gain_rate' => -8.5,
            ]);
            signalListTestBuySignal($holdingSnapshot, [
                'signal_type' => 'rsi_oversold_rebound',
                'reason_summary' => 'RSIが28から34へ反発しました',
            ]);
            signalListTestFundamentalIndicator($holding, ['equity_ratio' => 58.0, 'roe' => 15.2]);

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('サンプル素材');
            $component->assertSee('5201');

            $html = $component->html();
            // unrealized_gain_rate（符号付きパーセントルール: sprintf('%+.1f%%', $value)相当）
            expect($html)->toContain('-8.5%');
            expect($html)->toContain('rsi_oversold_rebound'); // buy_signal_types badge (raw signal_type)
            expect($html)->toContain('RSIが28から34へ反発しました'); // buy_signal_reason_summary
            expect($html)->toContain('ROE'); // fundamental_summary（財務健全性サマリ）
            expect($html)->toContain('58'); // equity_ratio appearing within fundamental_summary

            // 分割買い下がりの提案（3段階）: 現在値(1000)／-7%地点(930)／-15%地点(850)
            // （価格ルール: number_format($value, 2)相当。カンマ区切り + 小数点2桁）。
            expect($html)->toContain('1,000.00');
            expect($html)->toContain('930.00');
            expect($html)->toContain('850.00');
        });
    });

    describe('NISA推奨表示', function () {
        test('nisa_recommended=trueの銘柄には、NISA推奨に関する文言が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '6758', 'market' => 'jp', 'symbol_name' => 'ソニーグループ']);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => -3.2]);
            signalListTestBuySignal($holdingSnapshot);
            signalListTestFundamentalIndicator($holding, ['equity_ratio' => 58.0, 'roe' => 15.2]);

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('ソニーグループ');
            $component->assertSee('NISA');
        });
    });

    describe('財務指標取得不可表示', function () {
        test('fundamental_status=unavailableの銘柄（米国株等）には、財務指標取得不可を示す文言が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding([
                'symbol_code' => 'EXMP', 'market' => 'us', 'symbol_name' => 'サンプル電子部品',
            ]);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 100.00, 'fx_rate_used' => 150.0, 'unrealized_gain_rate' => -12.0,
            ]);
            signalListTestBuySignal($holdingSnapshot, ['signal_type' => 'week52_low_proximity']);
            // Deliberately no FundamentalIndicator row created.

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('EXMP');
            $component->assertSee('取得不可');
        });
    });

    describe('空状態', function () {
        test('買い増し候補が0件の場合、空状態メッセージが表示される', function () {
            $user = User::factory()->create();

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('買い増しを検討できる押し目銘柄はありません');
        });
    });

    describe('画面構成: 2セクション同時表示', function () {
        test('買い増し候補セクションと利確検討セクションが同一画面内に両方とも表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            // 買い増し候補側: 押し目買いシグナルあり・利確シグナルなし
            $buyHolding = signalListTestHolding(['symbol_code' => '5201', 'market' => 'jp', 'symbol_name' => 'サンプル素材']);
            $buyHoldingSnapshot = signalListTestHoldingSnapshot($snapshot, $buyHolding, ['unrealized_gain_rate' => -8.5]);
            signalListTestBuySignal($buyHoldingSnapshot);
            signalListTestFundamentalIndicator($buyHolding);

            // 利確検討側: 含み益+20%超・利確シグナルあり
            $sellHolding = signalListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $sellHoldingSnapshot = signalListTestHoldingSnapshot($snapshot, $sellHolding, [
                'quantity' => 300, 'average_cost' => 1000.00, 'current_price' => 1300.00, 'unrealized_gain_rate' => 30.0,
            ]);
            signalListTestSignal($sellHoldingSnapshot, [
                'signal_type' => 'rsi_reversal', 'reason_summary' => 'RSIが72から65に反落',
            ]);

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertSee('買い増し候補');
            $component->assertSee('利確検討');
            $component->assertSee('サンプル素材');
            $component->assertSee('トヨタ自動車');
        });
    });
});

describe('判定チェックリスト表示（criteria、CHG-0007）', function () {
    describe('利確検討セクション', function () {
        test('各項目の基準ラベル・実測値・達成カウントが画面に描画される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '6526', 'market' => 'jp', 'symbol_name' => 'ソシオネクスト']);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300, 'average_cost' => 1000.00, 'current_price' => 1300.00, 'unrealized_gain_rate' => 30.0,
            ]);
            signalListTestSignal($holdingSnapshot, ['signal_type' => 'rsi_reversal', 'reason_summary' => 'RSIが72から65に反落']);
            signalListTestFundamentalIndicator($holding);
            // 全テクニカル項目 met になる値（current_price=1300 前提）
            signalListTestTechnicalIndicator($holding, [
                'rsi' => 78.0,
                'macd' => -3.0, 'macd_signal' => 1.0,
                'bb_upper' => 1250.0,
                'week52_high' => 1500.0,
                'relative_strength_vs_market' => -4.5,
            ]);

            $html = Livewire::actingAs($user)->test(SignalList::class)->html();

            // 項目名・基準ラベル・実測値
            expect($html)->toContain('RSI');
            expect($html)->toContain('≥70');
            expect($html)->toContain('78.0');
            // グループ別の達成サマリ（「7/7」等の表現。桁は実装で確定するが
            // 「テクニカル」「財務」のラベルと達成分母/分子は必ず出す）
            expect($html)->toContain('テクニカル');
            expect($html)->toContain('財務');
        });

        test('テクニカル指標が未取得の銘柄では該当項目が「—」で表示され画面が壊れない', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => 'AAPL', 'market' => 'us', 'symbol_name' => 'Apple']);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 10, 'average_cost' => 100.00, 'current_price' => 130.00, 'unrealized_gain_rate' => 30.0,
            ]);
            signalListTestSignal($holdingSnapshot, ['signal_type' => 'rsi_reversal', 'reason_summary' => 'RSIが72から65に反落']);
            // TechnicalIndicator / FundamentalIndicator を意図的に作らない

            $component = Livewire::actingAs($user)->test(SignalList::class);

            $component->assertOk();
            $component->assertSee('Apple');
            expect($component->html())->toContain('—');
        });
    });

    describe('買い増し候補セクション', function () {
        test('買い増し方向の基準ラベル（RSI ≤30・出来高倍率 ≥1.5倍 等）が描画される', function () {
            $user = User::factory()->create();
            [, $snapshot] = signalListTestImportBatch();

            $holding = signalListTestHolding(['symbol_code' => '5201', 'market' => 'jp', 'symbol_name' => 'サンプル素材']);
            $holdingSnapshot = signalListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100, 'average_cost' => 1200.00, 'current_price' => 1000.00, 'unrealized_gain_rate' => -8.5,
            ]);
            signalListTestBuySignal($holdingSnapshot, ['signal_type' => 'rsi_oversold_rebound']);
            signalListTestFundamentalIndicator($holding, ['equity_ratio' => 58.0, 'roe' => 15.2]);
            signalListTestTechnicalIndicator($holding, [
                'rsi' => 22.0,
                'macd' => 2.0, 'macd_signal' => -1.0,
                'bb_lower' => 1100.0,
                'ma20' => 1200.0,
                'week52_low' => 950.0,
                'volume' => 2_500_000, 'volume_ma20' => 1_000_000.0,
            ]);

            $html = Livewire::actingAs($user)->test(SignalList::class)->html();

            expect($html)->toContain('≤30');
            expect($html)->toContain('≥1.5倍');
            expect($html)->toContain('MA20乖離率');
        });
    });
});
