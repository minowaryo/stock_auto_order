<?php

namespace Tests\Feature;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Actions\Portfolio\ClassifyHoldingsAction;
use App\Livewire\ImportSummaryReport\Latest;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0008: 最新サマリーレポートのタブ化（UC-009 フロー7） — Red phase Feature
| Test (F-013 Cycle 2 書き換え)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-009（2026-09-17改訂、基本フロー5・
|     業務ルール「タブからの再表示」）・UC-013
|   - docs/adr/ADR-0014-portfolio-bucket-classification.md D9〜D9-4
|
| -------------------------------------------------------------------------
| このファイルの位置づけ（Cycle 2、既存テストの書き換え）
| -------------------------------------------------------------------------
| App\Livewire\ImportSummaryReport\Latest は実装済み（クラス・ルート
| （GET /summary-report）・Blade view いずれも存在する）だが、中身は
| ShowImportSummaryReportAction 経由で旧・候補選定ロジックの結果
| （top_recommendations 等）をそのまま表示している。そのため以下のテストは
| 「クラスが無くて fatal error になる」Redではなく、「$report に
| classification キーが無い」「旧フィールドが残っている」等の**アサーション
| 不一致によるRed**になる想定（意図した失敗であり、セットアップミスではない）。
|
| 「どのバッチが最新か」を判定するロジック自体（CHG-0008、成功バッチの
| スキップ等）は旧版で既にGreenだったはずの実装がそのまま残っている想定の
| ため、本ファイルはその判定ロジックの再検証（正常系・境界値の2ブロック）を
| 引き続き維持しつつ、レポート本文の中身をtop_recommendations前提から
| classification前提のアサーションに置き換える。ClassifyHoldingsAction自体の
| 分類ロジックはtests/Unit/Actions/Portfolio/ClassifyHoldingsActionTest.php
| （F-013 Cycle 1）の責務であり、本ファイル・tests/Feature/
| ImportSummaryReportShowTest.php（Cycle 2）で再検証済みのため、本ファイルは
| 「最新バッチ選択ロジックが分類俯瞰にも正しく波及すること」の最小限の確認に
| 留める。
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
        'relative_strength_vs_market' => 6.0,
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
        'roe' => 15.2,
        'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3,
        'equity_ratio' => 58.0,
        'operating_margin' => 18.3,
        'dividend_yield' => 2.0,
        'dividend_payout_ratio' => 30.0,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * Seeds a "take_profit" qualifying holding (含み益+30%・RSI72, ADR-0014 D2
 * 通常モード+20%超) in its own Snapshot, so the resulting classification's
 * take_profit bucket contains an unambiguous marker (symbol_code/
 * symbol_name) identifying which batch's data was rendered.
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
    $holdingSnapshot = importSummaryReportLatestTestHoldingSnapshot($snapshot, $holding, [
        'average_cost' => 1000.0,
        'current_price' => 1300.0,
        'unrealized_gain_amount' => 3000.0,
        'unrealized_gain_rate' => 30.0,
    ]);
    importSummaryReportLatestTestTechnicalIndicator($holding, ['rsi' => 72.0]);
    importSummaryReportLatestTestFundamentalIndicator($holding);
    // シグナル1件を発生させ、CHG-0006の動的分岐（シグナル0件かつ財務健全性
    // passedだと高水準モード+150%超が適用される）を回避し、通常モード
    // （+20%超）で take_profit 対象になるようにする（含み益+30%）。
    Signal::create([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72まで上昇し利確ラインを超過しました',
    ]);

    return ['batch' => $batch, 'symbol_code' => $symbolCode, 'symbol_name' => $symbolName];
}

describe('CHG-0008: 最新サマリーレポートのタブ化（UC-009 フロー7）— 分類俯瞰（ADR-0014 D9）', function () {
    describe('正常系', function () {
        test('取込バッチが複数ある場合、最新バッチの分類俯瞰（take_profitバケツ）が表示される', function () {
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

            expect($component->get('report'))->toHaveKey('classification');
            $takeProfitBucket = collect($component->get('report')['classification']['buckets'])->firstWhere('bucket', 'take_profit');
            expect(collect($takeProfitBucket['holdings'])->pluck('symbol_code')->all())->toContain($newest['symbol_code']);
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

    describe('旧フィールドの廃止（ADR-0014 D9-1）', function () {
        test('$report に旧フィールド（top_recommendations/supplementary_recommendations）が含まれない', function () {
            $user = User::factory()->create();
            importSummaryReportLatestTestSeedTakeProfitBatch('NEW');

            $component = Livewire::actingAs($user)->test(Latest::class);

            expect($component->get('report'))->not->toHaveKey('top_recommendations');
            expect($component->get('report'))->not->toHaveKey('supplementary_recommendations');
            expect($component->get('report'))->toHaveKey('classification');
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

    describe('副作用（GETで再集計が走るAction）', function () {
        test('ShowImportSummaryReportActionはmount時に1回だけ呼び出される', function () {
            $user = User::factory()->create();
            $batch = importSummaryReportLatestTestImportBatch();
            importSummaryReportLatestTestSnapshot($batch);

            // 有効な（キーが揃った）空のclassification構造を、実際の
            // ClassifyHoldingsAction（保有銘柄0件）から得て使う（Show側の
            // 同種テストと同じ意図）。
            $classification = app(ClassifyHoldingsAction::class)->execute();
            $fakeResult = [
                'portfolio_headline' => 'テスト用ヘッドライン（Latest）',
                'generated_at' => now(),
                'classification' => $classification,
            ];

            $this->mock(ShowImportSummaryReportAction::class, function ($mock) use ($fakeResult) {
                $mock->shouldReceive('execute')->once()->andReturn($fakeResult);
            });

            Livewire::actingAs($user)->test(Latest::class)
                ->assertSee('テスト用ヘッドライン（Latest）');

            // Mockery::once()の検証はこのテスト関数を抜ける際に行われる。
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/summary-reportにアクセスすると/loginへリダイレクトされる', function () {
            $batch = importSummaryReportLatestTestImportBatch();
            importSummaryReportLatestTestSnapshot($batch);

            $this->get('/summary-report')->assertRedirect('/login');
        });
    });

    describe('永続化なし（ADR-0014 D9-2）', function () {
        test('画面表示後もimport_summary_reports/import_summary_report_itemsへの書き込みは発生しない', function () {
            $user = User::factory()->create();
            importSummaryReportLatestTestSeedTakeProfitBatch('NEW');

            Livewire::actingAs($user)->test(Latest::class);

            $this->assertDatabaseCount('import_summary_reports', 0);
            $this->assertDatabaseCount('import_summary_report_items', 0);
        });
    });
});
