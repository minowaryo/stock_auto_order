<?php

namespace Tests\Feature;

use App\Actions\Portfolio\ClassifyHoldingsAction;
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
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-009: 取込後サマリーレポート — Red phase Feature Test (F-013 Cycle 2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-009（2026-09-17改訂: F-013/ADR-0014 D9で
|     旧・上位10〜20件レコメンドが分類俯瞰〔UC-013〕に完全に置き換わった版）
|   - docs/product/use-cases.md UC-013（分類俯瞰の出力表・業務ルール）
|   - docs/adr/ADR-0014-portfolio-bucket-classification.md D9〜D9-4（置き換え・
|     永続化廃止・portfolio_headline改訂・退役ロジック）
|
| -------------------------------------------------------------------------
| このファイルの位置づけ（Cycle 2、既存テストの書き換え）
| -------------------------------------------------------------------------
| 旧版のこのファイルは ShowImportSummaryReportAction 独自の候補選定ロジック
| （buildTakeProfitCandidates/buildRebalanceCandidates/buildNewCandidateItems/
| composite_score、ADR-0003）を前提にしたテストで埋まっていたが、ADR-0014 D9
| によりそれらは退役対象と確定した。本ファイルはUC-009の新しい契約（
| ClassifyHoldingsAction〔UC-013〕の出力をそのまま`classification`に埋め込む・
| `portfolio_headline`はバケツ件数の集計文・永続化廃止）に全面的に書き換える。
|
| App\Actions\ImportSummaryReport\ShowImportSummaryReportAction /
| App\Http\Controllers\ImportSummaryReportController / ルート
| （GET /api/import-batches/{importBatch}/summary-report）はいずれも実装済み
| だが、中身は旧・候補選定ロジックのまま（D9-1〜D9-4未着手）。そのため
| 以下のテストは「クラスが無くて fatal error になる」Redではなく、
| 「`classification`キーが無い／`top_recommendations`が残っている／
| `portfolio_headline`が旧フォーマットのまま／DBに書き込みが発生する」等の
| **アサーション不一致によるRed**になる想定（意図した失敗であり、セットアップ
| ミスではない）。ClassifyHoldingsAction 自体（F-013 Cycle 1）は既にGreenで
| マージ済みのため、本ファイルは同Actionの分類ロジック自体（バケツ判定・
| hold_watch判定・ソート順等）を再検証しない（tests/Unit/Actions/Portfolio/
| ClassifyHoldingsActionTest.php の責務。重複させない）。
|
| Assumptions made while writing these tests (Gate 4で異なる契約が良ければ
| 指摘してください):
|   - ShowImportSummaryReportAction::execute(ImportBatch $importBatch): array
|     のシグネチャ自体は変更しない（既存のController/Livewireからの呼び出し
|     と揃える）。ただし分類俯瞰データ自体はClassifyHoldingsAction同様
|     「直近スナップショット」を対象に算出される想定であり、$importBatch
|     引数は主に画面のURL・表示用（取込日時ラベル等）に使われるのみと仮定
|     する。本ファイルの全テストは1バッチ・1スナップショットのみのシナリオ
|     で構成しているため、この仮定の違いによる曖昧さは生じない。
|   - `classification`はClassifyHoldingsAction::execute()の戻り値
|     （classified_at/group_summary/hold_breakdown/buckets/
|     sector_overweight_summary/new_entry_reference）をキー名そのままで
|     埋め込む。
|   - `portfolio_headline`の具体的な文言フォーマットは実装時に確定するため、
|     本ファイルでは「旧来の単一候補ハイライト文（'最優先候補:'等）ではない
|     こと」「空文字でないこと」のみを検証し、厳密な文言はアサートしない。
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom009TestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom009TestHolding(array $attributes = []): Holding
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
function ucFrom009TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
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
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom009TestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
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
function ucFrom009TestBuySignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): BuySignal
{
    return BuySignal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => 'RSIが28から34へ反発しました',
    ], $attributes));
}

function ucFrom009TestSectorClassification(string $name, ?string $code = null): SectorClassification
{
    return SectorClassification::create([
        'code' => $code,
        'name' => $name,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom009TestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 50.0,
        'macd' => null,
        'macd_signal' => null,
        'ma20' => null,
        'ma75' => null,
        'bb_upper' => null,
        'bb_lower' => null,
        'relative_strength_vs_market' => 6.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom009TestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 15.2,
        'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3,
        'equity_ratio' => 58.0,
        'dividend_yield' => 2.0,
        'dividend_payout_ratio' => 30.0,
        'operating_margin' => 18.3,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * Fetch the UC-009 summary report as an authenticated user.
 */
function ucFrom009TestFetchReport(TestCase $test, int|ImportBatch $importBatch, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();
    $importBatchId = $importBatch instanceof ImportBatch ? $importBatch->id : $importBatch;

    return $test->actingAs($user)->getJson("/api/import-batches/{$importBatchId}/summary-report");
}

/**
 * Seeds one holding per bucket (core_accumulation/loss_review/take_profit/
 * add_on/hold) under the same snapshot, mirroring the fixture style already
 * proven in tests/Unit/Actions/Portfolio/ClassifyHoldingsActionTest.php.
 *
 * @return array<string, string> symbol_code keyed by bucket name
 */
function ucFrom009TestSeedAllBuckets(Snapshot $snapshot): array
{
    $etf = ucFrom009TestHolding([
        'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf',
        'symbol_name' => 'Vanguard Total Stock Market ETF',
    ]);
    ucFrom009TestHoldingSnapshot($snapshot, $etf, ['quantity' => 50, 'current_price' => 300]);

    $lossReview = ucFrom009TestHolding(['symbol_code' => 'LR01', 'symbol_name' => '整理検討テスト']);
    ucFrom009TestHoldingSnapshot($snapshot, $lossReview, [
        'current_price' => 750, 'unrealized_gain_amount' => -25000, 'unrealized_gain_rate' => -25.0,
    ]);
    ucFrom009TestTechnicalIndicator($lossReview);
    ucFrom009TestFundamentalIndicator($lossReview);

    $takeProfit = ucFrom009TestHolding(['symbol_code' => 'TP01', 'symbol_name' => '利確検討テスト']);
    $takeProfitSnapshot = ucFrom009TestHoldingSnapshot($snapshot, $takeProfit, [
        'current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0,
    ]);
    ucFrom009TestSignal($takeProfitSnapshot);
    ucFrom009TestTechnicalIndicator($takeProfit);
    ucFrom009TestFundamentalIndicator($takeProfit);

    $addOn = ucFrom009TestHolding(['symbol_code' => 'AO01', 'symbol_name' => '買い増し検討テスト']);
    $addOnSnapshot = ucFrom009TestHoldingSnapshot($snapshot, $addOn);
    ucFrom009TestBuySignal($addOnSnapshot);
    ucFrom009TestTechnicalIndicator($addOn);
    ucFrom009TestFundamentalIndicator($addOn);

    $hold = ucFrom009TestHolding(['symbol_code' => 'HD01', 'symbol_name' => 'キープテスト']);
    ucFrom009TestHoldingSnapshot($snapshot, $hold, [
        'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
    ]);
    ucFrom009TestTechnicalIndicator($hold);
    ucFrom009TestFundamentalIndicator($hold);

    return [
        'core_accumulation' => 'VTI',
        'loss_review' => 'LR01',
        'take_profit' => 'TP01',
        'add_on' => 'AO01',
        'hold' => 'HD01',
    ];
}

/**
 * Minimal 楽天証券 JP stock CSV (1 row), trimmed down from
 * tests/Feature/UC001CsvImportTest.php's ucFrom001TestJpStockCsv() — only
 * used here to exercise the real ImportCsvAction pipeline for the
 * "永続化廃止（D9-2）" placeholder-removal test.
 */
function ucFrom009TestMinimalJpStockCsv(): string
{
    $lines = [
        '■現在の評価額合計［円］,,"0"',
        '■評価損益合計,前日比［円］,"0"',
        ',前月比［円］,"0"',
        ',評価損益［円］,"0"',
        '',
        '■特定口座',
        '',
        '銘柄コード,銘柄名,保有数量［株］,執行中［株］,(内訳　通常数量[株]),(内訳　積立数量[株]),平均取得価額［円］,取得総額［円］,現在値［円］,現在値（前日比）［円］,時価評価額［円］,評価損益［円］',
        '"7203","トヨタ自動車","10","0","10","0","2,000.00","0","2,500.0","0.0","0","0"',
        ',,,,,,特定口座合計,"0",,,"0","0"',
    ];

    return implode("\r\n", $lines)."\r\n";
}

/**
 * Minimal 楽天証券 US stock CSV (1 row), trimmed down from
 * tests/Feature/UC001CsvImportTest.php's ucFrom001TestUsStockCsv().
 */
function ucFrom009TestMinimalUsStockCsv(): string
{
    $lines = [
        '■時価評価額合計［USドル］,"0",■前日比合計［USドル］,"0",■評価損益額合計［USドル］,"0",,時間外株価を含まない',
        '■円換算時価評価額合計,"0",■円換算前日比合計,"0",■円換算評価損益額合計,"0",,"参考為替レート(米ドル)","159.32","円/USD","08/15 06:00"',
        '',
        '■特定口座',
        '',
        'ティッカー,銘柄名,取引所,保有数量［株］,執行中数量［株］,(内訳　通常数量[株]),(内訳　積立数量[株]),表示通貨,平均取得価額［USドル］,取得総額［USドル］,現在値［USドル］,前日比［USドル］,時価評価額［USドル］,評価損益［USドル］',
        '"AAPL","アップル","米国市場","5","-","-","-","USドル","100.00","0","150.00","0.00","0","0"',
        ',,,,,,,,特定口座合計,"0",,,"0","0"',
    ];

    return implode("\r\n", $lines)."\r\n";
}

function ucFrom009TestFakeCsvFile(string $filename, string $shiftJisContent): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, mb_convert_encoding($shiftJisContent, 'SJIS-win', 'UTF-8'));
}

describe('UC-009: 取込後サマリーレポート（分類俯瞰への置き換え、ADR-0014 D9）', function () {
    describe('正常系（レポート基本構造）', function () {
        test('取込後サマリーレポートを取得できる（portfolio_headline・generated_at・classificationが返る）', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedAllBuckets($snapshot);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['portfolio_headline'])->toBeString();
            expect(trim((string) $data['portfolio_headline']))->not->toBe('');
            expect($data['generated_at'])->not->toBeNull();

            expect($data)->toHaveKey('classification');
            expect($data['classification'])->toHaveKeys([
                'group_summary', 'hold_breakdown', 'buckets', 'sector_overweight_summary', 'new_entry_reference',
            ]);
        });

        test('portfolio_headlineはバケツ件数の集計文であり、旧・単一候補ハイライト文（最優先候補:）ではない', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedAllBuckets($snapshot);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $headline = (string) $response->json('data.portfolio_headline');
            expect($headline)->not->toContain('最優先候補');
            expect($headline)->not->toContain('件の候補を検出しました');
            // ADR-0014 D9-3: バケツ件数・構成比の集計文（具体的フォーマットは
            // Green実装時に確定するため、数値を含むことのみを検証する）。
            expect(preg_match('/\d/', $headline))->toBe(1);
        });

        test('旧フィールド（top_recommendations/supplementary_recommendations）はレスポンスに含まれない', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedAllBuckets($snapshot);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();
            $response->assertJsonMissingPath('data.top_recommendations');
            $response->assertJsonMissingPath('data.supplementary_recommendations');
        });
    });

    describe('正常系（分類俯瞰の埋め込み、UC-013との整合性）', function () {
        test('classificationはClassifyHoldingsActionの分類結果（各バケツの銘柄）をそのまま反映する', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            $symbolsByBucket = ucFrom009TestSeedAllBuckets($snapshot);

            $expected = app(ClassifyHoldingsAction::class)->execute();

            $response = ucFrom009TestFetchReport($this, $batch);
            $response->assertSuccessful();

            $buckets = collect($response->json('data.classification.buckets'));

            foreach ($symbolsByBucket as $bucket => $symbolCode) {
                $actualSymbols = $buckets->firstWhere('bucket', $bucket)['holdings'] ?? [];
                $expectedSymbols = collect($expected['buckets'])->firstWhere('bucket', $bucket)['holdings'] ?? [];

                expect(collect($actualSymbols)->pluck('symbol_code')->all())
                    ->toBe(collect($expectedSymbols)->pluck('symbol_code')->all());
                expect(collect($actualSymbols)->pluck('symbol_code')->all())->toContain($symbolCode);
            }

            expect($response->json('data.classification.group_summary'))
                ->toEqual(json_decode(json_encode($expected['group_summary']), true));
            expect($response->json('data.classification.hold_breakdown'))
                ->toEqual(json_decode(json_encode($expected['hold_breakdown']), true));
        });

        test('セクター偏りサマリがレスポンスに含まれ、偏り警告セクターの銘柄行にoverweight_sectorが立つ', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();

            $semiconductor = ucFrom009TestSectorClassification('半導体');
            $automobile = ucFrom009TestSectorClassification('自動車');

            $overweight = ucFrom009TestHolding(['symbol_code' => 'SEC1', 'symbol_name' => '半導体株', 'sector_classification_id' => $semiconductor->id]);
            ucFrom009TestHoldingSnapshot($snapshot, $overweight, ['current_price' => 800, 'unrealized_gain_rate' => 0.0]);
            ucFrom009TestTechnicalIndicator($overweight);
            ucFrom009TestFundamentalIndicator($overweight);

            $healthy = ucFrom009TestHolding(['symbol_code' => 'AUTO1', 'symbol_name' => '自動車株', 'sector_classification_id' => $automobile->id]);
            ucFrom009TestHoldingSnapshot($snapshot, $healthy, ['current_price' => 200, 'unrealized_gain_rate' => 0.0]);
            ucFrom009TestTechnicalIndicator($healthy);
            ucFrom009TestFundamentalIndicator($healthy);

            $response = ucFrom009TestFetchReport($this, $batch);
            $response->assertSuccessful();

            $sectorSummary = collect($response->json('data.classification.sector_overweight_summary'));
            expect($sectorSummary->pluck('sector_name')->all())->toContain('半導体');

            $holdBucket = collect($response->json('data.classification.buckets'))->firstWhere('bucket', 'hold');
            $overweightRow = collect($holdBucket['holdings'])->firstWhere('symbol_code', 'SEC1');
            expect($overweightRow['overweight_sector'])->toBeTrue();
        });
    });

    describe('永続化廃止（ADR-0014 D9-2）', function () {
        test('レポート取得後もimport_summary_reports/import_summary_report_itemsへの書き込みは発生しない', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedAllBuckets($snapshot);

            ucFrom009TestFetchReport($this, $batch)->assertSuccessful();
            ucFrom009TestFetchReport($this, $batch)->assertSuccessful();

            $this->assertDatabaseCount('import_summary_reports', 0);
            $this->assertDatabaseCount('import_summary_report_items', 0);
        });

        test('ImportCsvAction実行後もImportSummaryReportのプレースホルダー行は作られない', function () {
            app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient);
            app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
            app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient);
            app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);

            $user = User::factory()->create();

            $response = $this->actingAs($user)->post('/api/csv-import', [
                'jp_stock_file' => ucFrom009TestFakeCsvFile('jp_stock.csv', ucFrom009TestMinimalJpStockCsv()),
                'us_stock_file' => ucFrom009TestFakeCsvFile('us_stock.csv', ucFrom009TestMinimalUsStockCsv()),
            ], ['Accept' => 'application/json']);

            $response->assertSuccessful();
            $this->assertDatabaseCount('import_batches', 1);

            // ADR-0014 D9-2: ImportCsvAction が取込完了時に作成していた
            // ImportSummaryReport::create() のプレースホルダー行を廃止する。
            $this->assertDatabaseCount('import_summary_reports', 0);
        });
    });

    describe('異常系・境界値', function () {
        test('対象となる保有銘柄が存在しない場合は分類対象なしの空状態レポートになる', function () {
            [$batch] = ucFrom009TestImportBatch();
            // Deliberately no Holding/HoldingSnapshot rows created at all.

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data)->toHaveKey('classification');
            foreach ($data['classification']['group_summary'] as $group) {
                expect((float) $group['market_value_total'])->toEqualWithDelta(0.0, 0.01);
                expect($group['holding_count'])->toBe(0);
            }
            foreach ($data['classification']['buckets'] as $bucket) {
                expect($bucket['holdings'])->toBe([]);
            }
        });

        test('存在しない取込バッチIDを指定した場合は404になる', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->getJson('/api/import-batches/999999/summary-report');

            $response->assertStatus(404);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは取込後サマリーレポートを取得できない', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            $holding = ucFrom009TestHolding();
            ucFrom009TestHoldingSnapshot($snapshot, $holding, [
                'unrealized_gain_amount' => 3000.0,
                'unrealized_gain_rate' => 30.0,
            ]);

            $response = $this->getJson("/api/import-batches/{$batch->id}/summary-report");

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001/002/003).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
