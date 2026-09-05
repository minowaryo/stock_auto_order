<?php

namespace Tests\Feature;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Livewire\ImportSummaryReport\Latest;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0008: 最新サマリーレポートのタブ化（UC-009 フロー7） — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-009 基本フロー7・業務ルール「タブからの
|     再表示」・エラーケース「スナップショットを持つ取込バッチが1件も
|     存在しない」)
|   - stock_auto_order-latest-summary-report-tab-implementation-phase.md
|     （プランファイル）
|
| None of the following exist yet at Red time:
|   - App\Livewire\ImportSummaryReport\Latest（クラス自体が無い）
|   - GET /summary-report ルート（routes/web.php未追加）
|   - resources/views/livewire/import-summary-report/latest.blade.php
|   - ナビゲーション($navItems)への 'summary-report' エントリ
|
| Expected Red failure modes:
|   - Tests that call Livewire::test(Latest::class) directly (test 1, 2, 3,
|     5) fail with a "class not found" style fatal error when the `use
|     App\Livewire\ImportSummaryReport\Latest;` statement above is resolved
|     at first reference (PHP does not error on an unresolved `use` import
|     by itself — only when the class name is actually referenced, which
|     happens the moment Livewire::test(Latest::class) or `Latest::class`
|     is evaluated).
|   - Tests that hit GET /summary-report over plain HTTP (test 4, 6) fail
|     because the route does not exist at all yet: today this returns
|     Laravel's default "no matching route" 404 response, NOT a 200 with
|     the empty-state markup (test 4) and NOT a redirect produced by the
|     `auth` middleware (test 6 — it coincidentally 404s today for the
|     WRONG reason, same caveat pattern as
|     ImportSummaryReportShowTest.php's 存在しない取込バッチID test). Both
|     must be re-verified once Green work adds the route, to confirm they
|     then fail/pass for the intended reason (empty-state markup missing /
|     auth middleware redirect), not because the route is entirely absent.
|
| Seed helper functions below (importSummaryReportLatestTest*) are a
| structural duplicate of tests/Feature/ImportSummaryReportShowTest.php's
| importSummaryReportShowTest* helpers (unique prefix to avoid cross-file
| redeclaration errors), reused here to build multiple ImportBatch/Snapshot
| combinations for "which batch is 'latest'" scenarios rather than the
| single-batch scenarios that file covers.
|
| Assumptions made while writing these tests (flag at Gate 4 if a different
| contract is preferred):
|   - Latest::mount() takes no route parameter (unlike Show::mount(ImportBatch
|     $importBatch)) — it resolves "the latest import batch with a snapshot"
|     itself, per the plan file's
|     `ImportBatch::query()->whereHas('snapshot')->orderByDesc('imported_at')
|     ->orderByDesc('id')->first()` spec. Tests 1/2/3/5 therefore call
|     Livewire::test(Latest::class) with no constructor arguments.
|   - The empty-state message key text is exactly "まだCSVの取込がありません"
|     (from use-cases.md's UC-009 エラーケース表, verbatim) with a link to
|     /csv-import (mirrors the existing holding-list.blade.php empty-state
|     link pattern: `<a href="/csv-import" wire:navigate>...`).
|
*/

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportLatestTestImportBatch(array $attributes = []): ImportBatch
{
    return ImportBatch::create(array_merge([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportLatestTestSnapshot(ImportBatch $batch, array $attributes = []): Snapshot
{
    return Snapshot::create(array_merge([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportLatestTestHolding(array $attributes = []): Holding
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
function importSummaryReportLatestTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 1000,
        'current_price' => 1000,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 0.0,
        'is_newly_detected' => false,
    ], $attributes));
}

function importSummaryReportLatestTestSectorClassification(string $name, ?string $code = null): SectorClassification
{
    return SectorClassification::create(['code' => $code, 'name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportLatestTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 70.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportLatestTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 8.0,
        'revenue_growth' => 5.0,
        'operating_income_growth' => 4.0,
        'equity_ratio' => 35.0,
        'dividend_yield' => 2.0,
        'dividend_payout_ratio' => 30.0,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * Seeds a "利確検討" qualifying holding (含み益+30%・RSI72, no signals ->
 * normal-mode +20%超 gate, mirrors ImportSummaryReportShowTest.php's
 * take-profit fixture) in its own Snapshot, so the resulting report's
 * headline/top_recommendations contain an unambiguous marker
 * (symbol_code/symbol_name) identifying which batch's data was rendered.
 *
 * @return array{batch: ImportBatch, symbol_code: string, symbol_name: string}
 */
function importSummaryReportLatestTestSeedTakeProfitBatch(string $label, array $batchAttributes = []): array
{
    $batch = importSummaryReportLatestTestImportBatch($batchAttributes);
    $snapshot = importSummaryReportLatestTestSnapshot($batch);

    $symbolCode = "9{$label}";
    $symbolName = "テスト銘柄{$label}";

    $sector = importSummaryReportLatestTestSectorClassification("セクター{$label}");
    $holding = importSummaryReportLatestTestHolding([
        'symbol_code' => $symbolCode,
        'symbol_name' => $symbolName,
        'sector_classification_id' => $sector->id,
    ]);
    importSummaryReportLatestTestHoldingSnapshot($snapshot, $holding, [
        'average_cost' => 1000.0,
        'current_price' => 1300.0,
        'unrealized_gain_amount' => 3000.0,
        'unrealized_gain_rate' => 30.0,
    ]);
    importSummaryReportLatestTestTechnicalIndicator($holding, ['rsi' => 72.0]);

    return ['batch' => $batch, 'symbol_code' => $symbolCode, 'symbol_name' => $symbolName];
}

describe('CHG-0008: 最新サマリーレポートのタブ化（UC-009 フロー7）', function () {
    describe('正常系', function () {
        test('取込バッチが複数ある場合、最新バッチのレポート（headline・上位10件）が表示される', function () {
            $user = User::factory()->create();

            importSummaryReportLatestTestSeedTakeProfitBatch('OLD', [
                'imported_at' => now()->subDays(2),
            ]);
            $newest = importSummaryReportLatestTestSeedTakeProfitBatch('NEW', [
                'imported_at' => now(),
            ]);

            $component = Livewire::actingAs($user)->test(Latest::class);

            $component->assertSee($newest['symbol_code']);
            $component->assertSee($newest['symbol_name']);
        });

        test('古い取込バッチにしか存在しない銘柄は表示されない（最新バッチのみを見ていることの確認）', function () {
            $user = User::factory()->create();

            $old = importSummaryReportLatestTestSeedTakeProfitBatch('OLD', [
                'imported_at' => now()->subDays(2),
            ]);
            importSummaryReportLatestTestSeedTakeProfitBatch('NEW', [
                'imported_at' => now(),
            ]);

            $component = Livewire::actingAs($user)->test(Latest::class);

            $component->assertDontSee($old['symbol_code']);
            $component->assertDontSee($old['symbol_name']);
        });
    });

    describe('境界値', function () {
        test('スナップショットを持たない失敗バッチが取込日時上は最新でも、それをスキップして直近の成功バッチを表示する', function () {
            $user = User::factory()->create();

            $successful = importSummaryReportLatestTestSeedTakeProfitBatch('OK', [
                'imported_at' => now()->subDay(),
            ]);

            // 取込自体は完了扱いだがCSVパース失敗等でスナップショットが
            // 作成されなかった想定バッチ。imported_atは$successfulより新しい。
            importSummaryReportLatestTestImportBatch([
                'status' => 'failed',
                'imported_at' => now(),
            ]);

            $component = Livewire::actingAs($user)->test(Latest::class);

            $component->assertSee($successful['symbol_code']);
            $component->assertSee($successful['symbol_name']);
        });
    });

    describe('空状態', function () {
        test('取込バッチが1件も存在しない場合「まだCSVの取込がありません」とCSV取込画面への導線が表示され、Actionは呼ばれない', function () {
            $user = User::factory()->create();

            $this->mock(ShowImportSummaryReportAction::class, function ($mock) {
                $mock->shouldNotReceive('execute');
            });

            $response = $this->actingAs($user)->get('/summary-report');

            $response->assertSuccessful();
            $response->assertSee('まだCSVの取込がありません');
            $response->assertSee('href="/csv-import"', false);
        });
    });

    describe('副作用（GETで再集計・書き込みが走るAction）', function () {
        test('ShowImportSummaryReportActionはmount時に1回だけ呼び出される', function () {
            $user = User::factory()->create();
            $batch = importSummaryReportLatestTestImportBatch();
            importSummaryReportLatestTestSnapshot($batch);

            $fakeResult = [
                'portfolio_headline' => 'テスト用ヘッドライン（Latest）',
                'generated_at' => now(),
                'top_recommendations' => [],
                'supplementary_recommendations' => [],
            ];

            $this->mock(ShowImportSummaryReportAction::class, function ($mock) use ($fakeResult) {
                $mock->shouldReceive('execute')->once()->andReturn($fakeResult);
            });

            Livewire::actingAs($user)->test(Latest::class)
                ->assertSee('テスト用ヘッドライン（Latest）');

            // Mockery::once()の検証（未実装クラスのためこの行に到達する前に
            // 「class not found」でRedになる想定 — Latest作成後も、mount()
            // 以外（render()等）でexecute()を再度呼んでいれば
            // "should be called exactly 1 times but called 2 times"で
            // Redのままになる）。
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/summary-reportにアクセスすると/loginへリダイレクトされる', function () {
            $batch = importSummaryReportLatestTestImportBatch();
            importSummaryReportLatestTestSnapshot($batch);

            // NOTE: coincidentally already 404s today because the route
            // itself does not exist yet (see file-level docblock caveat).
            // Re-verify once the route is added, to confirm the redirect
            // then comes from the `auth` middleware, not route absence.
            $this->get('/summary-report')->assertRedirect('/login');
        });
    });
});
