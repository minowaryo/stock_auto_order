<?php

namespace Tests\Feature;

use App\Livewire\Sector\SectorDashboard;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchedTheme;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-005: セクター配分ダッシュボード（Livewireフルページ） — Red phase
| Livewire Component Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-005 基本フロー・出力表・業務ルール)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     holding_snapshot_accounts / snapshots / sector_classifications /
|     fundamental_indicators / watched_themes)
|   - app/Actions/Sector/ShowSectorDashboardAction.php (既にGreen。
|     execute(): array{sectors: array, rebalance_candidates: array}を
|     副作用なしで返す。render()のたびに毎回呼び直す規約はHoldingList/
|     SignalListと同じ)
|   - docs/product/mockups/screen-UC005-sector-dashboard.html
|     （配分バー + 目安ライン40% + リバランス提案テーブル）
|   - C:\Users\minow\.claude\plans\stock_auto_order-frontend-implementation-phase.md
|     Phase 6
|
| App\Livewire\Sector\SectorDashboard does not exist yet (no class, no
| route, no Blade view). Every Livewire::test(SectorDashboard::class) call
| below is expected to fail with a "class not found" style fatal error, and
| the plain HTTP guest test is expected to fail because GET /sector-dashboard
| is not yet a registered web route (only GET /api/sector-dashboard exists
| per Phase 0's route split). That is the intended Red state, not a
| typo/setup bug.
|
| This file reuses App\Actions\Sector\ShowSectorDashboardAction unmodified
| (pure read, no side effects, safe to call fresh on every render()). It
| does NOT re-test ShowSectorDashboardAction's own calculation rules
| (allocation_rate weighting, NISA exclusion for suggested_sell_amount/
| suggested_sell_quantity, rebalance candidate extraction/exclusion) — those
| are already covered by tests/Feature/UC005SectorDashboardTest.php against
| the JSON API. This file only verifies "the screen renders what the Action
| returns" (same division of responsibility as SignalListTest.php vs.
| UC004SignalListTest.php — 30-testing.md CRUD網羅ルール／このタスクの指示に
| 従う).
|
| Fixture-building helpers below are a fresh copy (unique
| `sectorDashboardTest` prefix, same convention as `ucFrom005SectorTest*` in
| tests/Feature/UC005SectorDashboardTest.php and `signalListTest*` in
| tests/Feature/SignalListTest.php) to avoid cross-file function
| redeclaration errors while keeping fixture shapes consistent with the
| already-Green JSON API test.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag at Gate 4 review if a different contract is
| preferred):
|   - Route: GET /sector-dashboard, `auth` middleware, Livewire full-page
|     component, added to the same `Route::middleware('auth')->group(...)`
|     block as /holdings, /signals etc. in routes/web.php (Green-phase task).
|   - render() calls ShowSectorDashboardAction fresh every time (no
|     mount()-only caching), same "call on every render" convention as
|     HoldingList/SignalList.
|   - allocation bar: a simple element with an inline `width: X%`-bearing
|     style attribute is sufficient (no JS/chart library). This file asserts
|     on the percentage value appearing in a `width` style declaration via
|     assertSeeHtml, not on exact pixel/DOM structure.
|   - allocation_status badge variant mapping: 偏り警告→danger (bg-red-100),
|     やや偏り→warning (bg-amber-100), matching <x-badge>'s existing variant
|     vocabulary (resources/views/components/badge.blade.php). Per
|     use-cases.md業務ルール「健全のセクターにはバッジを表示しない」, this file
|     asserts the literal text "健全" is NOT rendered anywhere for a healthy
|     sector (no badge, no plain-text label either) — flag at Gate 4 if a
|     plain-text (non-badge) "健全" label is preferred instead of full
|     suppression.
|   - suggested_sell_amount/suggested_sell_quantity are shown only for rows
|     where is_overweight is true (per use-cases.md出力表 and the Action's
|     own contract that these fields are null for non-overweight rows).
|   - rebalance candidate rows link to /candidate-check?symbol_code={code}
|     (same temporary-link convention already used by
|     resources/views/livewire/holding/holding-list.blade.php's NEW badge
|     link, per the task's own instruction).
|   - NISA推奨候補には<x-badge>等でNISA関連の文言（"NISA"という文字列を含む）
|     が表示される — 具体的な文言・デザインはGate4で調整可能な叩き台。
|   - Empty state message for zero rebalance candidates: "リバランス候補はあ
|     りません"（タスク指示に明記された文言をそのまま採用）。
|   - Unauthenticated access redirects to /login (302), same convention as
|     HoldingList/SignalList (this is a Livewire full-page screen behind the
|     `auth` middleware, not the JSON API sibling endpoint which tolerates
|     302/401/403).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function sectorDashboardTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function sectorDashboardTestHolding(array $attributes = []): Holding
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
function sectorDashboardTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => 1300,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 30000,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function sectorDashboardTestFundamental(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'equity_ratio' => 45.0,
        'roe' => 12.0,
        'fetched_at' => now(),
    ], $attributes));
}

function sectorDashboardTestSector(string $name): SectorClassification
{
    return SectorClassification::query()->firstOrCreate(['name' => $name]);
}

describe('UC-005: セクター配分ダッシュボード（Livewire）', function () {
    describe('正常系: セクター配分バーの表示', function () {
        test('複数セクターのsector_name/allocation_rate/allocation_statusが配分バーとともに表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = sectorDashboardTestImportBatch();

            // 半導体: 450,000円 / 総額1,000,000円 = 45% -> やや偏り
            $semiconductorSector = sectorDashboardTestSector('半導体');
            $semiconductorHolding = sectorDashboardTestHolding([
                'symbol_code' => '6920', 'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $semiconductorHolding, [
                'quantity' => 100, 'current_price' => 4500.00,
            ]);

            // 自動車: 300,000円 = 30% -> 健全
            $autoSector = sectorDashboardTestSector('自動車');
            $autoHolding = sectorDashboardTestHolding([
                'symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 100, 'current_price' => 3000.00,
            ]);

            // 未分類: 250,000円 = 25% -> 健全
            $fundHolding = sectorDashboardTestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式', 'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund', 'symbol_name' => 'eMAXIS Slim 全世界株式',
                'sector_classification_id' => null,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $fundHolding, [
                'quantity' => 1000000, 'current_price' => 2500.00,
            ]);

            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            $component->assertSee('半導体');
            $component->assertSee('自動車');
            $component->assertSee('未分類');

            $html = $component->html();
            expect($html)->toContain('45'); // 半導体 allocation_rate
            expect($html)->toContain('30'); // 自動車 allocation_rate
            expect($html)->toContain('25'); // 未分類 allocation_rate
            expect($html)->toContain('やや偏り');

            // 配分バー: パーセンテージを含むwidthスタイルが描画される
            // (CSSのみのバー実装。JS/チャートライブラリ不要)。
            expect($html)->toMatch('/width:\s*45(\.0)?%/');

            // 業務ルール（use-cases.md）:
            // 「健全のセクターにはバッジを表示しない（情報過多を避けるため）」
            // -> 自動車・未分類はどちらも健全のため、"健全"という文字列自体が
            // 画面のどこにも表示されない。
            expect($html)->not->toContain('健全');
        });
    });

    describe('偏り警告セクターの売却提案', function () {
        test('is_overweight=trueの偏り警告セクターは売却提案額・株数が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = sectorDashboardTestImportBatch();

            // 半導体: 800,000円 / 1,000,000円 = 80% -> 偏り警告
            $semiconductorSector = sectorDashboardTestSector('半導体');
            $semiconductorHolding = sectorDashboardTestHolding([
                'symbol_code' => '6920', 'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $semiconductorHolding, [
                'quantity' => 80, 'current_price' => 10000.00,
            ]);

            // 自動車: 200,000円 = 20% -> 健全
            $autoSector = sectorDashboardTestSector('自動車');
            $autoHolding = sectorDashboardTestHolding([
                'symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 200, 'current_price' => 1000.00,
            ]);

            // suggested_sell_amount = (80% - 70%) * 1,000,000 = 100,000
            // suggested_sell_quantity = 100,000 / 10,000(加重平均現在値) = 10

            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            $html = $component->html();
            expect($html)->toContain('偏り警告');
            expect($html)->toContain('100,000'); // suggested_sell_amount
            expect($html)->toContain('10'); // suggested_sell_quantity
        });

        test('健全/やや偏りセクターには売却提案（suggested_sell_amount/quantity）が表示されない', function () {
            $user = User::factory()->create();
            [, $snapshot] = sectorDashboardTestImportBatch();

            // 半導体: 450,000円 / 1,000,000円 = 45% -> やや偏り（is_overweight=false）
            $semiconductorSector = sectorDashboardTestSector('半導体');
            $semiconductorHolding = sectorDashboardTestHolding([
                'symbol_code' => '6920', 'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $semiconductorHolding, [
                'quantity' => 100, 'current_price' => 4500.00,
            ]);

            // 自動車: 300,000円 = 30% -> 健全（is_overweight=false）
            $autoSector = sectorDashboardTestSector('自動車');
            $autoHolding = sectorDashboardTestHolding([
                'symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 100, 'current_price' => 3000.00,
            ]);

            // 未分類: 250,000円 = 25% -> 健全（is_overweight=false）
            $fundHolding = sectorDashboardTestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式', 'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund', 'symbol_name' => 'eMAXIS Slim 全世界株式',
                'sector_classification_id' => null,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $fundHolding, [
                'quantity' => 1000000, 'current_price' => 2500.00,
            ]);

            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            // is_overweightが1件も存在しないため、"目安"となる売却提案表現
            // （円・株の提案）は一切表示されない。
            $component->assertDontSee('提案');
        });
    });

    describe('リバランス提案', function () {
        test('候補銘柄が一覧表示され、symbol_name/sector_name/reason/suggested_purchase_amountおよび/candidate-checkへのリンクを持つ', function () {
            $user = User::factory()->create();
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = sectorDashboardTestImportBatch();

            // 既存ポートフォリオ（候補抽出には無関係なセクターのみ、偏り無し）
            $materialSector = sectorDashboardTestSector('素材');
            $heldStock = sectorDashboardTestHolding([
                'symbol_code' => '9999', 'symbol_name' => '既存保有株',
                'sector_classification_id' => $materialSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $heldStock, [
                'quantity' => 100, 'current_price' => 5000.00, // 500,000円
            ]);

            $candidateSector = sectorDashboardTestSector('AI半導体');
            $candidate = sectorDashboardTestHolding([
                'symbol_code' => '6920', 'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $candidateSector->id,
            ]);
            sectorDashboardTestFundamental($candidate, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            $component->assertSee('レーザーテック');
            $component->assertSee('6920');
            $component->assertSee('AI半導体');

            $html = $component->html();
            expect($html)->toContain('45'); // 推薦理由（自己資本比率45%）
            expect($html)->toContain('12'); // 推薦理由（ROE12%）

            $component->assertSeeHtml('href="/candidate-check?symbol_code=6920"');
        });

        test('NISA推奨候補（nisa_recommended=true）にはNISA関連の表示がある', function () {
            $user = User::factory()->create();
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = sectorDashboardTestImportBatch();

            $materialSector = sectorDashboardTestSector('素材');
            $heldStock = sectorDashboardTestHolding([
                'symbol_code' => '9999', 'symbol_name' => '既存保有株',
                'sector_classification_id' => $materialSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $heldStock, [
                'quantity' => 100, 'current_price' => 5000.00,
            ]);

            // NISA推奨基準（自己資本比率50%以上・ROE15%以上）を満たす候補。
            $candidateSector = sectorDashboardTestSector('AI半導体');
            $candidate = sectorDashboardTestHolding([
                'symbol_code' => '6920', 'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $candidateSector->id,
            ]);
            sectorDashboardTestFundamental($candidate, ['equity_ratio' => 60.0, 'roe' => 20.0]);

            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            $html = $component->html();
            expect($html)->toContain('レーザーテック');
            expect($html)->toContain('NISA');
        });

        test('リバランス候補が0件の場合、エラーにならず空状態メッセージが表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = sectorDashboardTestImportBatch();

            // WatchedThemeを1件も登録しないため、NewCandidateFinderは
            // 候補を1件も返さない（rebalance_candidatesが空配列になる）。
            $autoSector = sectorDashboardTestSector('自動車');
            $autoHolding = sectorDashboardTestHolding([
                'symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            sectorDashboardTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 100, 'current_price' => 3000.00,
            ]);

            $component = Livewire::actingAs($user)->test(SectorDashboard::class);

            $component->assertSee('リバランス候補はありません');
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/sector-dashboardにアクセスできない', function () {
            $this->get('/sector-dashboard')->assertRedirect('/login');
        });
    });
});
