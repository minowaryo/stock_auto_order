<?php

namespace Tests\Feature;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Actions\Portfolio\ClassifyHoldingsAction;
use App\Livewire\ImportSummaryReport\Show;
use App\Models\BuySignal;
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
| UC-009: 取込後サマリーレポート画面（Livewireフルページ） — Red phase Feature
| Test (F-013 Cycle 2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-009（2026-09-17改訂）・UC-013（分類俯瞰の
|     基本フロー2〜8・出力表・業務ルール）
|   - docs/adr/ADR-0014-portfolio-bucket-classification.md D9〜D9-4
|
| -------------------------------------------------------------------------
| このファイルの位置づけ（Cycle 2、既存テストの書き換え）
| -------------------------------------------------------------------------
| App\Livewire\ImportSummaryReport\Show / resources/views/livewire/
| import-summary-report/show.blade.php / resources/views/components/
| summary-report-body.blade.php はいずれも実装済みだが、中身は旧・候補選定
| ロジック（top_recommendations/supplementary_recommendations、ADR-0003）の
| ままである。そのため以下のテストは「クラスが無くて fatal error になる」
| Redではなく、「分類俯瞰セクションが表示されない／旧バッジ文言
| （おすすめ上位10件・補足レコメンド）が残っている／$report に
| classification キーが無い」等の**アサーション不一致によるRed**になる想定
| （意図した失敗であり、セットアップミスではない）。
|
| ClassifyHoldingsAction自体の分類ロジック（バケツ判定・hold_watch判定・
| ソート順）はtests/Unit/Actions/Portfolio/ClassifyHoldingsActionTest.php
| （F-013 Cycle 1、既にGreen）の責務であり、本ファイルでは再検証しない。
| 本ファイルは「Show画面がShowImportSummaryReportActionの返す
| classificationデータを正しく受け取り、俯瞰セクションとして表示すること」
| に専念する。
|
| Assumptions made while writing these tests (Gate 4で異なる契約が良ければ
| 指摘してください):
|   - Show::mount(ImportBatch $importBatch)は`$this->report`に
|     ShowImportSummaryReportAction::execute($importBatch)の戻り値
|     （portfolio_headline/generated_at/classification）をそのまま代入する
|     （既存のtop_recommendations版と同じ構造の踏襲）。Livewireコンポーネント
|     の`report`プロパティに`$component->get('report')`でアクセスできる想定
|     （Livewire::test()の標準機能）。
|   - Blade側の具体的なバッジ文言・DOM構造までは厳密にアサートせず、
|     「該当銘柄のsymbol_code/symbol_nameが画面に表示されること」
|     （バケツごとの俯瞰表示が実際にレンダリングされていること）と、
|     「$component->get('report')の中身が正しいこと」（データ配線の正しさ）
|     の両方で契約レベルの検証に留める（Green実装時の文言自由度を残すため）。
|   - 空状態メッセージは UC-009業務ルール「対象となる保有銘柄が存在しない」
|     エラーケースの文言「分類対象の保有銘柄がありません」（UC-013エラー
|     ケース準拠、2026-09-17改訂）を用いる想定。
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function importSummaryReportShowTestImportBatch(): array
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => now(),
    ]);

    return [$batch, $snapshot];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestHolding(array $attributes = []): Holding
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
function importSummaryReportShowTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
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

function importSummaryReportShowTestSectorClassification(string $name, ?string $code = null): SectorClassification
{
    return SectorClassification::create(['code' => $code, 'name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 50.0,
        'relative_strength_vs_market' => 6.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
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
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestBuySignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): BuySignal
{
    return BuySignal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => 'RSIが28から34へ反発しました',
    ], $attributes));
}

/**
 * Seeds one holding per bucket (core_accumulation/loss_review/take_profit/
 * add_on/hold), mirroring UC009ImportSummaryReportTest.php's
 * ucFrom009TestSeedAllBuckets() (duplicated with a unique prefix per this
 * repo's existing multi-file test convention).
 *
 * @return array<string, string> symbol_code keyed by bucket name
 */
function importSummaryReportShowTestSeedAllBuckets(Snapshot $snapshot): array
{
    $etf = importSummaryReportShowTestHolding([
        'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf',
        'symbol_name' => 'Vanguard Total Stock Market ETF',
    ]);
    importSummaryReportShowTestHoldingSnapshot($snapshot, $etf, ['quantity' => 50, 'current_price' => 300]);

    $lossReview = importSummaryReportShowTestHolding(['symbol_code' => 'LR01', 'symbol_name' => '整理検討テスト']);
    importSummaryReportShowTestHoldingSnapshot($snapshot, $lossReview, [
        'current_price' => 750, 'unrealized_gain_amount' => -25000, 'unrealized_gain_rate' => -25.0,
    ]);
    importSummaryReportShowTestTechnicalIndicator($lossReview);
    importSummaryReportShowTestFundamentalIndicator($lossReview);

    $takeProfit = importSummaryReportShowTestHolding(['symbol_code' => 'TP01', 'symbol_name' => '利確検討テスト']);
    $takeProfitSnapshot = importSummaryReportShowTestHoldingSnapshot($snapshot, $takeProfit, [
        'current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0,
    ]);
    importSummaryReportShowTestSignal($takeProfitSnapshot);
    importSummaryReportShowTestTechnicalIndicator($takeProfit);
    importSummaryReportShowTestFundamentalIndicator($takeProfit);

    $addOn = importSummaryReportShowTestHolding(['symbol_code' => 'AO01', 'symbol_name' => '買い増し検討テスト']);
    $addOnSnapshot = importSummaryReportShowTestHoldingSnapshot($snapshot, $addOn);
    importSummaryReportShowTestBuySignal($addOnSnapshot);
    importSummaryReportShowTestTechnicalIndicator($addOn);
    importSummaryReportShowTestFundamentalIndicator($addOn);

    $hold = importSummaryReportShowTestHolding(['symbol_code' => 'HD01', 'symbol_name' => 'キープテスト']);
    importSummaryReportShowTestHoldingSnapshot($snapshot, $hold, [
        'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
    ]);
    importSummaryReportShowTestTechnicalIndicator($hold);
    importSummaryReportShowTestFundamentalIndicator($hold);

    return [
        'core_accumulation' => 'VTI',
        'loss_review' => 'LR01',
        'take_profit' => 'TP01',
        'add_on' => 'AO01',
        'hold' => 'HD01',
    ];
}

describe('UC-009: 取込後サマリーレポート画面（Livewire）— 分類俯瞰（ADR-0014 D9）', function () {
    describe('正常系（分類俯瞰セクションの表示）', function () {
        test('3分類（減らす/保つ/増やす）それぞれの銘柄が表示され、group_summaryの件数に反映される', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            $symbolsByBucket = importSummaryReportShowTestSeedAllBuckets($snapshot);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            foreach ($symbolsByBucket as $symbolCode) {
                $component->assertSee($symbolCode);
            }

            expect($component->get('report'))->toHaveKey('classification');
            $groupSummary = collect($component->get('report')['classification']['group_summary'])->keyBy('group');
            expect($groupSummary['reduce']['holding_count'])->toBe(2); // loss_review + take_profit
            expect($groupSummary['hold']['holding_count'])->toBe(2); // core_accumulation + hold
            expect($groupSummary['increase']['holding_count'])->toBe(1); // add_on
        });

        test('『保つ』の内訳（積立・インデックスコア/キープ）がhold_breakdownに反映され、両方の銘柄が表示される', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            $symbolsByBucket = importSummaryReportShowTestSeedAllBuckets($snapshot);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $component->assertSee($symbolsByBucket['core_accumulation']);
            $component->assertSee($symbolsByBucket['hold']);

            expect($component->get('report'))->toHaveKey('classification');
            $holdBreakdown = $component->get('report')['classification']['hold_breakdown'];
            expect($holdBreakdown['core_accumulation']['holding_count'])->toBe(1);
            expect($holdBreakdown['hold']['holding_count'])->toBe(1);
        });

        test('セクター偏りサマリが表示され、偏り警告セクターの銘柄行にoverweight_sectorが立つ', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();

            $semiconductor = importSummaryReportShowTestSectorClassification('半導体');
            $automobile = importSummaryReportShowTestSectorClassification('自動車');

            $overweight = importSummaryReportShowTestHolding(['symbol_code' => 'SEC1', 'symbol_name' => '半導体株', 'sector_classification_id' => $semiconductor->id]);
            importSummaryReportShowTestHoldingSnapshot($snapshot, $overweight, ['current_price' => 800, 'unrealized_gain_rate' => 0.0]);
            importSummaryReportShowTestTechnicalIndicator($overweight);
            importSummaryReportShowTestFundamentalIndicator($overweight);

            $healthy = importSummaryReportShowTestHolding(['symbol_code' => 'AUTO1', 'symbol_name' => '自動車株', 'sector_classification_id' => $automobile->id]);
            importSummaryReportShowTestHoldingSnapshot($snapshot, $healthy, ['current_price' => 200, 'unrealized_gain_rate' => 0.0]);
            importSummaryReportShowTestTechnicalIndicator($healthy);
            importSummaryReportShowTestFundamentalIndicator($healthy);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $component->assertSee('半導体');

            expect($component->get('report'))->toHaveKey('classification');
            $classification = $component->get('report')['classification'];
            expect(collect($classification['sector_overweight_summary'])->pluck('sector_name')->all())->toContain('半導体');

            $holdBucket = collect($classification['buckets'])->firstWhere('bucket', 'hold');
            $overweightRow = collect($holdBucket['holdings'])->firstWhere('symbol_code', 'SEC1');
            expect($overweightRow['overweight_sector'])->toBeTrue();
        });

        test('holdバケツの要観察（hold_watch）フラグが立つ銘柄が表示データ・画面の両方に反映される', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();

            $watched = importSummaryReportShowTestHolding(['symbol_code' => 'WATCH1', 'symbol_name' => '要観察株']);
            importSummaryReportShowTestHoldingSnapshot($snapshot, $watched, [
                'current_price' => 1020, 'unrealized_gain_amount' => 2000, 'unrealized_gain_rate' => 2.0,
            ]);
            importSummaryReportShowTestTechnicalIndicator($watched);
            // 財務健全性 failed（ADR-0014 D5 hold_watch判定(a)）。
            importSummaryReportShowTestFundamentalIndicator($watched, ['equity_ratio' => 20.0, 'roe' => 3.0]);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $component->assertSee('WATCH1');

            expect($component->get('report'))->toHaveKey('classification');
            $holdBucket = collect($component->get('report')['classification']['buckets'])->firstWhere('bucket', 'hold');
            $row = collect($holdBucket['holdings'])->firstWhere('symbol_code', 'WATCH1');
            expect($row['hold_watch'])->toBeTrue();
        });

        test('保有銘柄が0件の場合、分類対象なしの空状態が表示される', function () {
            $user = User::factory()->create();
            [$batch] = importSummaryReportShowTestImportBatch();
            // Deliberately no Holding/HoldingSnapshot rows created at all.

            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch])
                ->assertSee('分類対象の保有銘柄がありません');
        });
    });

    describe('旧フィールド・旧表示の廃止（ADR-0014 D9-1）', function () {
        test('$report に旧フィールド（top_recommendations/supplementary_recommendations）が含まれない', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedAllBuckets($snapshot);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            expect($component->get('report'))->not->toHaveKey('top_recommendations');
            expect($component->get('report'))->not->toHaveKey('supplementary_recommendations');
        });

        test('旧・上位10件/補足レコメンドの見出し文言は画面に表示されない', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedAllBuckets($snapshot);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $component->assertDontSee('おすすめ上位10件');
            $component->assertDontSee('補足レコメンド（11〜20位）');
        });
    });

    describe('異常系・境界値', function () {
        test('存在しない取込バッチIDを指定した場合は404になる', function () {
            $user = User::factory()->create();

            $this->actingAs($user)->get('/import-batches/999999/summary-report')->assertStatus(404);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは取込後サマリーレポート画面にアクセスできない', function () {
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            $holding = importSummaryReportShowTestHolding();
            importSummaryReportShowTestHoldingSnapshot($snapshot, $holding, [
                'unrealized_gain_amount' => 3000.0,
                'unrealized_gain_rate' => 30.0,
            ]);

            $this->get("/import-batches/{$batch->id}/summary-report")->assertRedirect('/login');
        });
    });

    describe('副作用（GETで再集計が走るAction）', function () {
        test('ShowImportSummaryReportActionはmount時に1回だけ呼び出される', function () {
            $user = User::factory()->create();
            [$batch] = importSummaryReportShowTestImportBatch();

            // 有効な（キーが揃った）空のclassification構造を、実際の
            // ClassifyHoldingsAction（保有銘柄0件）から得て使う — Blade側が
            // classificationの各キーを前提に描画してもキー欠落で落ちない
            // ようにするため（旧版のtop_recommendations:[]と同じ意図）。
            $classification = app(ClassifyHoldingsAction::class)->execute();
            $fakeResult = [
                'portfolio_headline' => 'テスト用ヘッドライン',
                'generated_at' => now(),
                'classification' => $classification,
            ];

            $this->mock(ShowImportSummaryReportAction::class, function ($mock) use ($fakeResult) {
                $mock->shouldReceive('execute')->once()->andReturn($fakeResult);
            });

            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch])
                ->assertSee('テスト用ヘッドライン');

            // Mockery::once()の検証はこのテスト関数を抜ける際に行われる。
        });
    });

    describe('永続化なし（ADR-0014 D9-2）', function () {
        test('画面表示後もimport_summary_reports/import_summary_report_itemsへの書き込みは発生しない', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedAllBuckets($snapshot);

            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $this->assertDatabaseCount('import_summary_reports', 0);
            $this->assertDatabaseCount('import_summary_report_items', 0);
        });
    });
});
