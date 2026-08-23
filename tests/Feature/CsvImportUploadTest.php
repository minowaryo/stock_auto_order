<?php

namespace Tests\Feature;

use App\Livewire\CsvImport\Upload;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-001: CSV取込画面（Livewireフルページ） — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-001)
|   - docs/architecture/data-model.md (import_batches / snapshots / holdings
|     / holding_snapshots)
|   - stock_auto_order-frontend-implementation-phase.md Phase 1
|
| App\Livewire\CsvImport\Upload does not exist yet (no class, no route, no
| Blade view). Every test below in describe('UC-001: CSV取込画面（Livewire）')
| is expected to fail with a "class not found" style fatal error when
| Livewire::test(Upload::class) is invoked (or a 404 for the not-yet-
| registered /csv-import route, for the plain HTTP guest test). That is the
| intended Red state, not a typo/setup bug.
|
| This file reuses App\Actions\Import\ImportCsvAction unmodified (already
| refactored in Phase 0 to accept plain UploadedFile arguments — Livewire's
| TemporaryUploadedFile extends Illuminate\Http\UploadedFile, so the
| component under test is expected to call
| app(ImportCsvAction::class)->execute($this->jp_stock_file,
| $this->us_stock_file, $this->mutual_fund_file) directly per
| .claude/rules/15-frontend.md "ロジックはapp/Services/やapp/Actions/に委譲する").
|
| CSV fixture byte content below is deliberately copied verbatim (same
| structure/values) from tests/Feature/UC001CsvImportTest.php's
| ucFrom001Test*() helpers, with a unique function-name prefix
| (csvImportUploadTest*) to avoid cross-file redeclaration errors while
| still guaranteeing Green behavior stays consistent with the
| already-proven JpStockCsvParser/UsStockCsvParser behavior exercised by
| that file.
|
| Assumptions made while writing these tests (flag at Gate 4 if a different
| contract is preferred):
|   - Route: GET /csv-import, `auth` middleware, Livewire full-page
|     component (stock_auto_order-frontend-implementation-phase.md Phase 1
|     table).
|   - Public property names on the component mirror
|     StoreCsvImportRequest's field names exactly (jp_stock_file,
|     us_stock_file, mutual_fund_file) so the component's own rules() can
|     reuse the same validation rule array without a name-mapping layer.
|   - The component's submit action is named `import()`.
|   - On success the component redirects (navigate: true) to
|     "/import-batches/{importBatchId}/summary-report" (UC-001 フロー10 /
|     stock_auto_order-frontend-implementation-phase.md Phase 1's "成功時は
|     UC-009へリダイレクト").
|   - On failure (ImportResult::success === false, i.e. ファイル全体がパース
|     不能), the component does NOT redirect and instead exposes
|     $result->failureReason so the view can display it inline. This test
|     file asserts that value is visible via assertSee() (not a fixed
|     literal string, since ImportCsvAction wraps the underlying
|     CsvStructureException's dynamic message rather than the literal
|     use-cases.md error table wording) and separately asserts, via a DB
|     read, that the persisted import_batches.failure_reason is non-null —
|     the same "compare against the DB's own persisted value" convention
|     UC001CsvImportTest.php's own equivalent test uses.
|   - 取込履歴 (import history) is rendered from a lightweight direct
|     ImportBatch::latest()-style query in the component (no new Action,
|     per the Phase 1 plan table's "軽量な読み取りのため新規Action不要"), with
|     newly-detected-count per historical batch derived via
|     HoldingSnapshot::whereHas('snapshot', ...)->where('is_newly_detected',
|     true)->count() (import_batches itself does not persist this count).
|
*/

/**
 * Convert UTF-8 text to the Shift-JIS (Windows-31J / CP932) byte string a
 * real 楽天証券 CSV export uses (UC-001 input validation:
 * "Shift-JISエンコード"). Verbatim copy of
 * UC001CsvImportTest.php::ucFrom001TestUtf8ToShiftJis().
 */
function csvImportUploadTestUtf8ToShiftJis(string $utf8): string
{
    return mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
}

function csvImportUploadTestCsvLine(array $values): string
{
    return implode(',', array_map(static fn ($v) => '"'.$v.'"', $values));
}

/**
 * Minimal single-section JP株CSV (only 特定口座 — sufficient for this
 * screen-level test file, which is not re-testing JpStockCsvParser's full
 * column/section behavior; that is already covered exhaustively by
 * tests/Unit/Services/Import/JpStockCsvParserTest.php and
 * tests/Feature/UC001CsvImportTest.php).
 *
 * @param  array<int, array{code: string, name: string, quantity: string, avg_cost: string, current_price: string}>  $rows
 */
function csvImportUploadTestJpStockCsv(array $rows): string
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
    ];

    foreach ($rows as $row) {
        $lines[] = csvImportUploadTestCsvLine([
            $row['code'], $row['name'], $row['quantity'], '0', $row['quantity'], '0',
            $row['avg_cost'], '0', $row['current_price'], '0.0', '0', '0',
        ]);
    }

    $lines[] = ',,,,,,特定口座合計,"0",,,"0","0"';

    return implode("\r\n", $lines)."\r\n";
}

/**
 * @param  array<int, array{ticker: string, name: string, quantity: string, avg_cost: string, current_price: string}>  $rows
 */
function csvImportUploadTestUsStockCsv(array $rows, string $fxRate = '159.32'): string
{
    $lines = [
        '■時価評価額合計［USドル］,"0",■前日比合計［USドル］,"0",■評価損益額合計［USドル］,"0",,時間外株価を含まない',
        '■円換算時価評価額合計,"0",■円換算前日比合計,"0",■円換算評価損益額合計,"0",,"参考為替レート(米ドル)","'.$fxRate.'","円/USD","08/15 06:00"',
        '',
        '■特定口座',
        '',
        'ティッカー,銘柄名,取引所,保有数量［株］,執行中数量［株］,(内訳　通常数量[株]),(内訳　積立数量[株]),表示通貨,平均取得価額［USドル］,取得総額［USドル］,現在値［USドル］,前日比［USドル］,時価評価額［USドル］,評価損益［USドル］',
    ];

    foreach ($rows as $row) {
        $lines[] = csvImportUploadTestCsvLine([
            $row['ticker'], $row['name'], '米国市場', $row['quantity'], '-', '-', '-', 'USドル',
            $row['avg_cost'], '0', $row['current_price'], '0.00', '0', '0',
        ]);
    }

    $lines[] = ',,,,,,,,特定口座合計,"0",,,"0","0"';

    return implode("\r\n", $lines)."\r\n";
}

/**
 * @param  array<int, array{fund_name: string, quantity: string, avg_cost: string, base_price: string}>  $rows
 */
function csvImportUploadTestMutualFundCsv(array $rows): string
{
    $lines = [
        '投資信託種別,口座区分,ファンド,分配金コース,保有数量[口],(内訳　通常数量[口]),(内訳　積立数量[口]),平均取得価額[円],取得総額[円],基準価額[円],基準価額(前日比)[円],基準価額(前月比)[円],時価評価額[円],評価損益[円],評価損益[％],トータルリターン[円],通貨単位,未収分配金,参考為替レート,時価評価額[外貨],合計額[円]',
    ];

    foreach ($rows as $row) {
        $lines[] = csvImportUploadTestCsvLine([
            '投資信託', '特定', $row['fund_name'], '再投資型', $row['quantity'], '-', '-',
            $row['avg_cost'], '0', $row['base_price'], '0', '0', '0', '0', '0.00', '0',
            '-', '-', '-', '-', '-',
        ]);
    }

    return implode("\r\n", $lines)."\r\n";
}

function csvImportUploadTestFakeCsvFile(string $filename, string $shiftJisOrRawContent): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, $shiftJisOrRawContent);
}

describe('UC-001: CSV取込画面（Livewire）', function () {
    beforeEach(function () {
        // Same convention as UC001CsvImportTest.php: bind Fakes for all 4
        // MarketData client interfaces so ImportCsvAction's post-import
        // FetchExternalMarketDataAction call never makes a real HTTP call.
        app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient);
        app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
        app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient);
        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    });

    describe('正常系', function () {
        test('国内株式・米国株式のCSVをアップロードして取込に成功するとサマリーレポート画面へリダイレクトされる', function () {
            $user = User::factory()->create();

            $jpCsv = csvImportUploadTestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = csvImportUploadTestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $component = Livewire::actingAs($user)->test(Upload::class)
                ->set('jp_stock_file', csvImportUploadTestFakeCsvFile('jp_stock.csv', csvImportUploadTestUtf8ToShiftJis($jpCsv)))
                ->set('us_stock_file', csvImportUploadTestFakeCsvFile('us_stock.csv', csvImportUploadTestUtf8ToShiftJis($usCsv)));

            $component->call('import');

            $batch = ImportBatch::latest('id')->first();
            expect($batch)->not->toBeNull();
            expect($batch->status)->toBe('completed');
            expect($batch->imported_count)->toBe(2);

            $component->assertRedirect("/import-batches/{$batch->id}/summary-report");
        });

        test('投資信託CSVなしでも取込に成功しサマリーレポート画面へリダイレクトされる', function () {
            $user = User::factory()->create();

            $jpCsv = csvImportUploadTestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = csvImportUploadTestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $component = Livewire::actingAs($user)->test(Upload::class)
                ->set('jp_stock_file', csvImportUploadTestFakeCsvFile('jp_stock.csv', csvImportUploadTestUtf8ToShiftJis($jpCsv)))
                ->set('us_stock_file', csvImportUploadTestFakeCsvFile('us_stock.csv', csvImportUploadTestUtf8ToShiftJis($usCsv)));

            $component->call('import');

            $batch = ImportBatch::latest('id')->first();
            expect($batch)->not->toBeNull();
            expect($batch->status)->toBe('completed');
            expect($batch->mutual_fund_filename)->toBeNull();

            $component->assertRedirect("/import-batches/{$batch->id}/summary-report");
        });

        test('投資信託CSVもあわせてアップロードすると3ファイルとも取り込まれる', function () {
            $user = User::factory()->create();

            $jpCsv = csvImportUploadTestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = csvImportUploadTestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);
            $mfCsv = csvImportUploadTestMutualFundCsv([
                ['fund_name' => '楽天・全米株式インデックス・ファンド(楽天・VTI)', 'quantity' => '100', 'avg_cost' => '10,000.00', 'base_price' => '12,000'],
            ]);

            Livewire::actingAs($user)->test(Upload::class)
                ->set('jp_stock_file', csvImportUploadTestFakeCsvFile('jp_stock.csv', csvImportUploadTestUtf8ToShiftJis($jpCsv)))
                ->set('us_stock_file', csvImportUploadTestFakeCsvFile('us_stock.csv', csvImportUploadTestUtf8ToShiftJis($usCsv)))
                ->set('mutual_fund_file', csvImportUploadTestFakeCsvFile('mutual_fund.csv', csvImportUploadTestUtf8ToShiftJis($mfCsv)))
                ->call('import');

            $batch = ImportBatch::latest('id')->first();
            expect($batch)->not->toBeNull();
            expect($batch->mutual_fund_filename)->toBe('mutual_fund.csv');
            expect($batch->imported_count)->toBe(3);
        });
    });

    describe('異常系・バリデーション', function () {
        test('国内株式CSVが未選択の場合バリデーションエラーになる', function () {
            $user = User::factory()->create();

            $usCsv = csvImportUploadTestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            Livewire::actingAs($user)->test(Upload::class)
                ->set('us_stock_file', csvImportUploadTestFakeCsvFile('us_stock.csv', csvImportUploadTestUtf8ToShiftJis($usCsv)))
                ->call('import')
                ->assertHasErrors('jp_stock_file');

            expect(ImportBatch::count())->toBe(0);
        });

        test('米国株式CSVが未選択の場合バリデーションエラーになる', function () {
            $user = User::factory()->create();

            $jpCsv = csvImportUploadTestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);

            Livewire::actingAs($user)->test(Upload::class)
                ->set('jp_stock_file', csvImportUploadTestFakeCsvFile('jp_stock.csv', csvImportUploadTestUtf8ToShiftJis($jpCsv)))
                ->call('import')
                ->assertHasErrors('us_stock_file');

            expect(ImportBatch::count())->toBe(0);
        });

        test('CSVを1つも選択せずに取込を実行するとjp_stock_file・us_stock_fileの両方がバリデーションエラーになる', function () {
            $user = User::factory()->create();

            Livewire::actingAs($user)->test(Upload::class)
                ->call('import')
                ->assertHasErrors(['jp_stock_file', 'us_stock_file']);

            expect(ImportBatch::count())->toBe(0);
        });

        test('パース不能なCSVをアップロードするとリダイレクトされず失敗理由が画面に表示される', function () {
            $user = User::factory()->create();

            // Deliberately not valid Shift-JIS and not shaped like the
            // expected 楽天証券 column structure at all — mirrors
            // UC001CsvImportTest.php's equivalent 422 test fixture.
            $unparseableJp = "\xFF\xFE\x00\x01\x02\x03BROKEN_NOT_A_VALID_CSV_STRUCTURE_\xDE\xAD\xBE\xEF\r\n".
                "\xFF\xFE\x00\x01\x02\x03BROKEN_NOT_A_VALID_CSV_STRUCTURE_\xDE\xAD\xBE\xEF\r\n";
            $usCsv = csvImportUploadTestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $component = Livewire::actingAs($user)->test(Upload::class)
                ->set('jp_stock_file', csvImportUploadTestFakeCsvFile('jp_stock.csv', $unparseableJp))
                ->set('us_stock_file', csvImportUploadTestFakeCsvFile('us_stock.csv', csvImportUploadTestUtf8ToShiftJis($usCsv)));

            $component->call('import');
            $component->assertNoRedirect();

            $batch = ImportBatch::latest('id')->first();
            expect($batch)->not->toBeNull();
            expect($batch->status)->toBe('failed');
            expect($batch->failure_reason)->not->toBeNull();

            $component->assertSee($batch->failure_reason);
        });
    });

    describe('取込履歴', function () {
        test('取込履歴が直近の取込バッチから表示され新規検出銘柄数が正しく表示される', function () {
            $user = User::factory()->create();

            $olderBatch = ImportBatch::create([
                'status' => 'completed',
                'jp_stock_filename' => 'jp_old.csv',
                'us_stock_filename' => 'us_old.csv',
                'mutual_fund_filename' => null,
                'imported_count' => 10,
                'error_count' => 0,
                'imported_at' => now()->subDays(2),
            ]);
            Snapshot::create(['import_batch_id' => $olderBatch->id, 'snapshotted_at' => now()->subDays(2)]);

            $newerBatch = ImportBatch::create([
                'status' => 'completed',
                'jp_stock_filename' => 'jp_new.csv',
                'us_stock_filename' => 'us_new.csv',
                'mutual_fund_filename' => 'mf_new.csv',
                'imported_count' => 13,
                'error_count' => 1,
                'imported_at' => now(),
            ]);
            $newerSnapshot = Snapshot::create(['import_batch_id' => $newerBatch->id, 'snapshotted_at' => now()]);

            $holdingA = Holding::create([
                'symbol_code' => '7203', 'market' => 'jp', 'instrument_type' => 'stock',
                'symbol_name' => 'トヨタ自動車', 'first_detected_at' => now(),
            ]);
            HoldingSnapshot::create([
                'snapshot_id' => $newerSnapshot->id, 'holding_id' => $holdingA->id,
                'quantity' => 10, 'average_cost' => 1000, 'current_price' => 1000,
                'unrealized_gain_amount' => 0, 'unrealized_gain_rate' => 0.0,
                'is_newly_detected' => true,
            ]);
            $holdingB = Holding::create([
                'symbol_code' => 'AAPL', 'market' => 'us', 'instrument_type' => 'stock',
                'symbol_name' => 'アップル', 'first_detected_at' => now(),
            ]);
            HoldingSnapshot::create([
                'snapshot_id' => $newerSnapshot->id, 'holding_id' => $holdingB->id,
                'quantity' => 5, 'average_cost' => 100, 'current_price' => 100,
                'unrealized_gain_amount' => 0, 'unrealized_gain_rate' => 0.0,
                'is_newly_detected' => true,
            ]);

            Livewire::actingAs($user)->test(Upload::class)
                ->assertSee('jp_new.csv')
                ->assertSee('jp_old.csv')
                ->assertSeeHtmlInOrder(['jp_new.csv', 'jp_old.csv']) // 直近の取込バッチが先頭
                ->assertSee('13')
                ->assertSee('2件'); // newerBatch の新規検出銘柄数
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/csv-importにアクセスできない', function () {
            $this->get('/csv-import')->assertRedirect('/login');
        });
    });
});
