<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-001: CSV import — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-001)
|   - docs/architecture/data-model.md (import_batches / snapshots / holdings / holding_snapshots)
|
| Nothing under app/ exists yet for this feature (no route, no controller,
| no FormRequest, no models, no migrations beyond the Laravel skeleton).
| Every test below is expected to fail for that reason (Red state).
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoint: POST /csv-import, authenticated via the standard "web"
|     session guard (single-user app, `docs/architecture/authz-authn.md`).
|   - Input field names follow use-cases.md exactly: jp_stock_file,
|     us_stock_file, mutual_fund_file.
|   - Requests send `Accept: application/json` so that FormRequest
|     validation failures map to Laravel's default 422 JSON response,
|     matching the HTTP status codes defined in UC-001's error table.
|   - The exact success response body/shape is intentionally NOT asserted
|     here (use-cases.md defines the output fields conceptually, not a
|     wire format). Success is instead verified against the Gate
|     3-approved DB schema (import_batches/snapshots/holdings/
|     holding_snapshots/import_summary_reports), which is the more
|     stable contract.
|   - `imported_count`/`error_count` count *symbols*, not raw CSV rows
|     (UC-001 output: "正常に取り込んだ銘柄数"). For the multi-account
|     aggregation test this distinction is intentionally left unasserted
|     to avoid over-constraining the aggregation algorithm.
|
*/

/**
 * Convert UTF-8 text to the Shift-JIS (Windows-31J / CP932) byte string
 * that a real 楽天証券 CSV export uses (UC-001 input validation:
 * "Shift-JISエンコード").
 */
function ucFrom001TestUtf8ToShiftJis(string $utf8): string
{
    return mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
}

/**
 * Quote each value like the real 楽天証券 CSV data rows do
 * (e.g. "1417","ミライト・ワン","3",...).
 */
function ucFrom001TestCsvLine(array $values): string
{
    return implode(',', array_map(static fn ($v) => '"'.$v.'"', $values));
}

/**
 * Build a JP stock CSV (国内株式CSV) matching 楽天証券's real export
 * structure: a summary header block, then one or more account sections
 * (■特定口座 / ■NISA成長投資枠), each with its own column header.
 *
 * @param  array<int, array{code: string, name: string, quantity: string, avg_cost: string, current_price: string}>  $tokuteiRows
 * @param  array<int, array{code: string, name: string, quantity: string, avg_cost: string, current_price: string}>  $nisaRows
 * @param  array<int, array{code: string, name: string, quantity: string, avg_cost: string, current_price: string}>|null  $malformedTokuteiRawLines
 */
function ucFrom001TestJpStockCsv(array $tokuteiRows, array $nisaRows = [], array $malformedTokuteiRawLines = []): string
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

    foreach ($tokuteiRows as $row) {
        $acquisitionTotal = $row['acquisition_total'] ?? '0';
        $marketValue = $row['market_value'] ?? '0';
        $gain = $row['gain'] ?? '0';

        $lines[] = ucFrom001TestCsvLine([
            $row['code'], $row['name'], $row['quantity'], '0', $row['quantity'], '0',
            $row['avg_cost'], $acquisitionTotal, $row['current_price'], '0.0', $marketValue, $gain,
        ]);
    }

    foreach ($malformedTokuteiRawLines as $rawLine) {
        $lines[] = $rawLine;
    }

    $lines[] = ',,,,,,特定口座合計,"0",,,"0","0"';

    if (! empty($nisaRows)) {
        $lines[] = '';
        $lines[] = '■NISA成長投資枠';
        $lines[] = '';
        $lines[] = '銘柄コード,銘柄名,保有数量［株］,執行中［株］,(内訳　保護預り数量[株]),(内訳　通常数量[株]),(内訳　積立数量[株]),(内訳　共有口座数量[株]),平均取得価額［円］,保護預り平均取得価額［円］,共有口座平均取得価額［円］,取得総額［円］,現在値［円］,現在値（前日比）［円］,時価評価額［円］,評価損益［円］,保護預り評価損益［円］,共有口座評価損益［円］';

        foreach ($nisaRows as $row) {
            $acquisitionTotal = $row['acquisition_total'] ?? '0';
            $marketValue = $row['market_value'] ?? '0';
            $gain = $row['gain'] ?? '0';

            $lines[] = ucFrom001TestCsvLine([
                $row['code'], $row['name'], $row['quantity'], '0', '-', $row['quantity'], '0', '-',
                $row['avg_cost'], '-', '-', $acquisitionTotal, $row['current_price'], '0.0', $marketValue, $gain, '-', '-',
            ]);
        }

        $lines[] = ',,,,,,,,,,NISA成長投資枠口座合計,"0",,,"0","0"';
    }

    return implode("\r\n", $lines)."\r\n";
}

/**
 * Build a US stock CSV (米国株式CSV) matching 楽天証券's real export
 * structure, including the reference FX rate header
 * ("参考為替レート(米ドル)") used for JPY conversion (UC-001業務ルール).
 *
 * $generalRows optionally adds a second "■一般口座" account section (実際の
 * ユーザーCSVに実在するセクション。ADR-0002 / NISA区分内訳の実装計画参照),
 * matching the way ucFrom001TestJpStockCsv() already supports a second
 * "■NISA成長投資枠" section via $nisaRows.
 *
 * @param  array<int, array{ticker: string, name: string, quantity: string, avg_cost: string, current_price: string}>  $rows
 * @param  array<int, array{ticker: string, name: string, quantity: string, avg_cost: string, current_price: string}>  $generalRows
 */
function ucFrom001TestUsStockCsv(array $rows, string $fxRate = '159.32', array $generalRows = []): string
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
        $acquisitionTotal = $row['acquisition_total'] ?? '0';
        $marketValue = $row['market_value'] ?? '0';
        $gain = $row['gain'] ?? '0';

        $lines[] = ucFrom001TestCsvLine([
            $row['ticker'], $row['name'], '米国市場', $row['quantity'], '-', '-', '-', 'USドル',
            $row['avg_cost'], $acquisitionTotal, $row['current_price'], '0.00', $marketValue, $gain,
        ]);
    }

    $lines[] = ',,,,,,,,特定口座合計,"0",,,"0","0"';

    if (! empty($generalRows)) {
        $lines[] = '';
        $lines[] = '■一般口座';
        $lines[] = '';
        $lines[] = 'ティッカー,銘柄名,取引所,保有数量［株］,執行中数量［株］,(内訳　通常数量[株]),(内訳　積立数量[株]),表示通貨,平均取得価額［USドル］,取得総額［USドル］,現在値［USドル］,前日比［USドル］,時価評価額［USドル］,評価損益［USドル］';

        foreach ($generalRows as $row) {
            $acquisitionTotal = $row['acquisition_total'] ?? '0';
            $marketValue = $row['market_value'] ?? '0';
            $gain = $row['gain'] ?? '0';

            $lines[] = ucFrom001TestCsvLine([
                $row['ticker'], $row['name'], '米国市場', $row['quantity'], '-', '-', '-', 'USドル',
                $row['avg_cost'], $acquisitionTotal, $row['current_price'], '0.00', $marketValue, $gain,
            ]);
        }

        $lines[] = ',,,,,,,,一般口座合計,"0",,,"0","0"';
    }

    return implode("\r\n", $lines)."\r\n";
}

/**
 * Build a mutual fund CSV (投資信託CSV) matching 楽天証券's real export
 * structure (single flat table, no account-section splitting).
 *
 * @param  array<int, array{fund_name: string, quantity: string, avg_cost: string, base_price: string}>  $rows
 */
function ucFrom001TestMutualFundCsv(array $rows): string
{
    $lines = [
        '投資信託種別,口座区分,ファンド,分配金コース,保有数量[口],(内訳　通常数量[口]),(内訳　積立数量[口]),平均取得価額[円],取得総額[円],基準価額[円],基準価額(前日比)[円],基準価額(前月比)[円],時価評価額[円],評価損益[円],評価損益[％],トータルリターン[円],通貨単位,未収分配金,参考為替レート,時価評価額[外貨],合計額[円]',
    ];

    foreach ($rows as $row) {
        $acquisitionTotal = $row['acquisition_total'] ?? '0';
        $marketValue = $row['market_value'] ?? '0';
        $gain = $row['gain'] ?? '0';

        $lines[] = ucFrom001TestCsvLine([
            '投資信託', '特定', $row['fund_name'], '再投資型', $row['quantity'], '-', '-',
            $row['avg_cost'], $acquisitionTotal, $row['base_price'], '0', '0', $marketValue, $gain, '0.00', $gain,
            '-', '-', '-', '-', '-',
        ]);
    }

    return implode("\r\n", $lines)."\r\n";
}

function ucFrom001TestFakeCsvFile(string $filename, string $shiftJisOrRawContent): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, $shiftJisOrRawContent);
}

/**
 * Submit the CSV import request as an authenticated user.
 *
 * @param  array<string, UploadedFile>  $files
 */
function ucFrom001TestSubmit(TestCase $test, array $files, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->post('/csv-import', $files, ['Accept' => 'application/json']);
}

describe('UC-001: CSV取込', function () {
    // ADR-0004 wiring: ImportCsvAction is expected to trigger
    // FetchExternalMarketDataAction (App\Actions\Analysis) once the DB
    // transaction that persists the snapshot/holdings completes
    // (use-cases.md UC-001 フロー7〜8). Once wired, every test in this file
    // exercises that call, so bind Fakes for all 4 MarketData client
    // interfaces here to guarantee no real HTTP call is ever made
    // (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md "テストでは
    // Fake実装に差し替える"). Empty-array responses (the Fake classes'
    // default constructor argument) mean every indicator ends up null
    // (data-insufficient), which does not affect any existing CSV-import
    // assertion below. Individual tests that need non-empty responses (or a
    // throwing Fake) re-bind the relevant interface(s) themselves.
    beforeEach(function () {
        app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient());
        app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient());
        app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient());
        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient());
    });

    describe('正常系', function () {
        test('国内株式・米国株式のCSVを取り込める', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ], fxRate: '159.32');

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $this->assertDatabaseCount('import_batches', 1);
            $this->assertDatabaseHas('import_batches', [
                'status' => 'completed',
                'jp_stock_filename' => 'jp_stock.csv',
                'us_stock_filename' => 'us_stock.csv',
                'imported_count' => 2,
                'error_count' => 0,
            ]);

            $batch = DB::table('import_batches')->first();
            expect($batch->mutual_fund_filename)->toBeNull();
            expect($batch->imported_at)->not->toBeNull();

            $this->assertDatabaseCount('snapshots', 1);
            $this->assertDatabaseHas('snapshots', ['import_batch_id' => $batch->id]);

            $this->assertDatabaseHas('holdings', ['symbol_code' => '7203', 'market' => 'jp', 'instrument_type' => 'stock']);
            $this->assertDatabaseHas('holdings', ['symbol_code' => 'AAPL', 'market' => 'us', 'instrument_type' => 'stock']);
            $this->assertDatabaseCount('holding_snapshots', 2);

            $jpHolding = DB::table('holdings')->where('symbol_code', '7203')->where('market', 'jp')->first();
            $jpSnapshot = DB::table('holding_snapshots')->where('holding_id', $jpHolding->id)->first();
            expect((float) $jpSnapshot->quantity)->toEqualWithDelta(10.0, 0.001);
            expect((float) $jpSnapshot->average_cost)->toEqualWithDelta(2000.0, 0.01);
            expect((float) $jpSnapshot->current_price)->toEqualWithDelta(2500.0, 0.01);
            expect($jpSnapshot->fx_rate_used)->toBeNull();
        });

        test('投資信託CSVも任意で取り込める', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);
            $mfCsv = ucFrom001TestMutualFundCsv([
                ['fund_name' => '楽天・全米株式インデックス・ファンド(楽天・VTI)', 'quantity' => '100', 'avg_cost' => '10,000.00', 'base_price' => '12,000'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
                'mutual_fund_file' => ucFrom001TestFakeCsvFile('mutual_fund.csv', ucFrom001TestUtf8ToShiftJis($mfCsv)),
            ]);

            $response->assertSuccessful();

            $this->assertDatabaseHas('import_batches', [
                'status' => 'completed',
                'mutual_fund_filename' => 'mutual_fund.csv',
                'imported_count' => 3,
                'error_count' => 0,
            ]);

            $this->assertDatabaseHas('holdings', [
                'symbol_code' => '楽天・全米株式インデックス・ファンド(楽天・VTI)',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
            ]);
        });

        test('同一銘柄が複数口座区分にまたがる場合は数量を合算し取得単価を加重平均で保存する', function () {
            // 特定口座: 7203 x100 @2,000円 / NISA成長投資枠: 7203 x50 @2,200円
            // -> 合算数量150、加重平均取得単価 (100*2000 + 50*2200) / 150 = 2066.666...
            $jpCsv = ucFrom001TestJpStockCsv(
                tokuteiRows: [
                    ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '100', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
                ],
                nisaRows: [
                    ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '50', 'avg_cost' => '2,200.00', 'current_price' => '2,500.0'],
                ],
            );
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '1', 'avg_cost' => '100.00', 'current_price' => '100.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            // Only one holdings row per (symbol_code, market) even though the
            // symbol appeared in two account sections of the CSV.
            $this->assertDatabaseCount('holdings', 2); // 7203 (jp) + MSFT (us)

            $holding = DB::table('holdings')->where('symbol_code', '7203')->where('market', 'jp')->first();
            $snapshot = DB::table('holding_snapshots')->where('holding_id', $holding->id)->first();

            expect((float) $snapshot->quantity)->toEqualWithDelta(150.0, 0.001);
            expect((float) $snapshot->average_cost)->toEqualWithDelta(2066.6667, 0.01);
        });

        // ADR-0002 / holding_snapshot_accounts write-path (NISA区分内訳の実装計画).
        // App\Models\HoldingSnapshotAccount / holding_snapshot_accounts table already
        // exist (created 2026-08-21), but nothing under app/ writes to it yet
        // (ImportCsvAction::aggregate()/execute() only ever create HoldingSnapshot
        // rows). Every test below is expected to fail because no rows are ever
        // inserted into holding_snapshot_accounts.
        test('同一銘柄が複数口座区分にまたがる場合はholding_snapshot_accountsに口座区分ごとの内訳が保存される', function () {
            // 特定口座: 7203 x60 @1,000円 + x40 @1,300円
            //   -> 特定口座内で合算100株、区分内加重平均 (60*1000+40*1300)/100 = 1,120円
            // NISA成長投資枠: 7203 x50 @2,200円
            // holding_snapshots（既存の合算値、回帰確認）:
            //   150株、加重平均 (60*1000+40*1300+50*2200)/150 = 1,480円
            $jpCsv = ucFrom001TestJpStockCsv(
                tokuteiRows: [
                    ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '60', 'avg_cost' => '1,000.00', 'current_price' => '2,500.0'],
                    ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '40', 'avg_cost' => '1,300.00', 'current_price' => '2,500.0'],
                ],
                nisaRows: [
                    ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '50', 'avg_cost' => '2,200.00', 'current_price' => '2,500.0'],
                ],
            );
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '1', 'avg_cost' => '100.00', 'current_price' => '100.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $holding = DB::table('holdings')->where('symbol_code', '7203')->where('market', 'jp')->first();
            $snapshot = DB::table('holding_snapshots')->where('holding_id', $holding->id)->first();

            // 回帰確認: 既存のholding_snapshots（合算値）は従来通り変更なく保存される
            expect((float) $snapshot->quantity)->toEqualWithDelta(150.0, 0.001);
            expect((float) $snapshot->average_cost)->toEqualWithDelta(1480.0, 0.01);

            $accounts = DB::table('holding_snapshot_accounts')
                ->where('holding_snapshot_id', $snapshot->id)
                ->get()
                ->keyBy('account_type');

            expect($accounts)->toHaveCount(2);

            expect((float) $accounts['specific']->quantity)->toEqualWithDelta(100.0, 0.001);
            expect((float) $accounts['specific']->average_cost)->toEqualWithDelta(1120.0, 0.01);

            expect((float) $accounts['nisa_growth']->quantity)->toEqualWithDelta(50.0, 0.001);
            expect((float) $accounts['nisa_growth']->average_cost)->toEqualWithDelta(2200.0, 0.01);
        });

        test('単一の口座区分にしか保有していない銘柄はholding_snapshot_accountsに1行だけ保存される', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '2', 'avg_cost' => '200.00', 'current_price' => '300.00'],
            ], fxRate: '150.00');

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $holding = DB::table('holdings')->where('symbol_code', 'MSFT')->where('market', 'us')->first();
            $snapshot = DB::table('holding_snapshots')->where('holding_id', $holding->id)->first();

            $accounts = DB::table('holding_snapshot_accounts')->where('holding_snapshot_id', $snapshot->id)->get();

            expect($accounts)->toHaveCount(1);
            expect($accounts[0]->account_type)->toBe('specific');
            expect((float) $accounts[0]->quantity)->toEqualWithDelta(2.0, 0.001);
            // average_cost は円換算後の値（200.00USD * 150.00円/USD）
            expect((float) $accounts[0]->average_cost)->toEqualWithDelta(200.0 * 150.0, 1.0);
        });

        test('米国株式CSVの一般口座セクションの保有銘柄はaccount_type=generalとしてholding_snapshot_accountsに保存される', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv(
                rows: [
                    ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '2', 'avg_cost' => '200.00', 'current_price' => '300.00'],
                ],
                fxRate: '150.00',
                generalRows: [
                    ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '3', 'avg_cost' => '250.00', 'current_price' => '300.00'],
                ],
            );

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $holding = DB::table('holdings')->where('symbol_code', 'MSFT')->where('market', 'us')->first();
            $snapshot = DB::table('holding_snapshots')->where('holding_id', $holding->id)->first();

            // 回帰確認: holding_snapshots（合算値）は一般口座分も合算した5株のまま
            expect((float) $snapshot->quantity)->toEqualWithDelta(5.0, 0.001);

            $accounts = DB::table('holding_snapshot_accounts')
                ->where('holding_snapshot_id', $snapshot->id)
                ->get()
                ->keyBy('account_type');

            expect($accounts)->toHaveCount(2);
            expect((float) $accounts['specific']->quantity)->toEqualWithDelta(2.0, 0.001);
            expect((float) $accounts['general']->quantity)->toEqualWithDelta(3.0, 0.001);
            expect((float) $accounts['general']->average_cost)->toEqualWithDelta(250.0 * 150.0, 1.0);
        });

        test('米国株式は参考為替レートで円換算して保存する', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '2', 'avg_cost' => '200.00', 'current_price' => '300.00'],
            ], fxRate: '150.00');

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $holding = DB::table('holdings')->where('symbol_code', 'MSFT')->where('market', 'us')->first();
            $snapshot = DB::table('holding_snapshots')->where('holding_id', $holding->id)->first();

            expect((float) $snapshot->fx_rate_used)->toEqualWithDelta(150.0, 0.001);
            // average_cost / current_price are stored in JPY (円建て), converted
            // using the CSV's reference FX rate.
            expect((float) $snapshot->average_cost)->toEqualWithDelta(200.00 * 150.00, 1.0);
            expect((float) $snapshot->current_price)->toEqualWithDelta(300.00 * 150.00, 1.0);
        });

        test('パースできない個別行はスキップしエラー件数に計上しつつ他の行の取込は継続する', function () {
            $jpCsv = ucFrom001TestJpStockCsv(
                tokuteiRows: [
                    ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
                    ['code' => '9984', 'name' => 'ソフトバンクグループ', 'quantity' => '1', 'avg_cost' => '3,000.00', 'current_price' => '5,000.0'],
                ],
                malformedTokuteiRawLines: [
                    // Quantity column is not a parseable number -> this row must
                    // be skipped and counted as an error, without failing the
                    // whole file (UC-001業務ルール).
                    ucFrom001TestCsvLine(['BADCODE', '不正データ銘柄', 'N/A', '0', 'N/A', '0', 'N/A', '0', '0', '0.0', '0', '0']),
                ],
            );
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '1', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $this->assertDatabaseHas('import_batches', [
                'status' => 'completed',
                'imported_count' => 3,
                'error_count' => 1,
            ]);

            $this->assertDatabaseMissing('holdings', ['symbol_code' => 'BADCODE']);
        });

        test('初回取込では全銘柄が新規検出銘柄として扱われない', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $newlyDetectedCount = DB::table('holding_snapshots')->where('is_newly_detected', true)->count();
            expect($newlyDetectedCount)->toBe(0);
        });

        test('2回目以降の取込では直前スナップショットに存在しなかった銘柄のみ新規検出銘柄として記録する', function () {
            $user = User::factory()->create();

            // --- 1st import: baseline (7203 / AAPL) ---
            $jpCsv1 = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv1 = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv1)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv1)),
            ], $user)->assertSuccessful();

            // --- 2nd import: 7203/AAPL still held, plus newly detected 9984/MSFT ---
            $jpCsv2 = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,600.0'],
                ['code' => '9984', 'name' => 'ソフトバンクグループ', 'quantity' => '1', 'avg_cost' => '3,000.00', 'current_price' => '5,000.0'],
            ]);
            $usCsv2 = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '160.00'],
                ['ticker' => 'MSFT', 'name' => 'マイクロソフト', 'quantity' => '2', 'avg_cost' => '200.00', 'current_price' => '300.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv2)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv2)),
            ], $user);

            $response->assertSuccessful();

            $this->assertDatabaseCount('snapshots', 2);
            $latestSnapshot = DB::table('snapshots')->orderByDesc('id')->first();

            $expectations = [
                '7203' => false,
                'AAPL' => false,
                '9984' => true,
                'MSFT' => true,
            ];

            foreach ($expectations as $symbolCode => $expectedNewlyDetected) {
                $holding = DB::table('holdings')->where('symbol_code', $symbolCode)->first();
                $snapshot = DB::table('holding_snapshots')
                    ->where('snapshot_id', $latestSnapshot->id)
                    ->where('holding_id', $holding->id)
                    ->first();

                expect((bool) $snapshot->is_newly_detected)
                    ->toBe($expectedNewlyDetected, "symbol_code={$symbolCode} の is_newly_detected が期待値と異なります");
            }
        });

        test('取込完了と同時に取込後サマリーレポート（UC-009）が自動生成される', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $batch = DB::table('import_batches')->first();

            $this->assertDatabaseCount('import_summary_reports', 1);
            $this->assertDatabaseHas('import_summary_reports', ['import_batch_id' => $batch->id]);

            $report = DB::table('import_summary_reports')->where('import_batch_id', $batch->id)->first();
            expect($report->portfolio_headline)->not->toBeNull();
            expect(trim((string) $report->portfolio_headline))->not->toBe('');
            expect($report->generated_at)->not->toBeNull();
        });

        test('CSV取込完了後、外部データ取得（テクニカル指標計算）が自動的に実行される', function () {
            // 25 weeks of ascending closes is enough for
            // TechnicalIndicatorCalculator to produce a non-null RSI (needs
            // >= 15 points) / MA20 / Bollinger Bands, without needing to
            // pin down the full 52/75-week indicators this test doesn't
            // assert on (docs/architecture/data-model.md technical_indicators).
            $jpHistory = [];
            $usHistory = [];
            $weekStart = new \DateTimeImmutable('2025-01-06');

            for ($i = 0; $i < 25; $i++) {
                $date = $weekStart->modify("+{$i} weeks")->format('Y-m-d');
                $jpHistory[] = ['date' => $date, 'close' => 2000.0 + $i * 10, 'volume' => 100000];
                $usHistory[] = ['date' => $date, 'close' => 100.0 + $i * 2, 'volume' => 50000];
            }

            app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['7203' => $jpHistory]));
            app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient(['AAPL' => $usHistory]));

            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();

            $jpHolding = DB::table('holdings')->where('symbol_code', '7203')->where('market', 'jp')->first();
            $usHolding = DB::table('holdings')->where('symbol_code', 'AAPL')->where('market', 'us')->first();
            expect($jpHolding)->not->toBeNull();
            expect($usHolding)->not->toBeNull();

            $this->assertDatabaseCount('technical_indicators', 2);
            $this->assertDatabaseHas('technical_indicators', ['holding_id' => $jpHolding->id]);
            $this->assertDatabaseHas('technical_indicators', ['holding_id' => $usHolding->id]);

            $jpIndicator = DB::table('technical_indicators')->where('holding_id', $jpHolding->id)->first();
            $usIndicator = DB::table('technical_indicators')->where('holding_id', $usHolding->id)->first();

            expect($jpIndicator->rsi)->not->toBeNull();
            expect($usIndicator->rsi)->not->toBeNull();
        });

        test('外部データ取得処理で予期しない例外が発生しても、CSV取込自体は成功する', function () {
            // FakeMarketIndexClient (tests/Support/Fakes/FakeMarketIndexClient.php)
            // has no throwsFor-style failure injection hook (confirmed by
            // reading the file before writing this test), so an inline
            // anonymous implementation of MarketIndexClientInterface is used
            // to simulate a failure that is NOT scoped to a single symbol
            // (unlike JpStockPriceClientInterface/UsStockPriceClientInterface's
            // per-symbol try/catch inside FetchExternalMarketDataAction) —
            // this exercises the outer try/catch that
            // ImportCsvAction is expected to wrap the whole
            // FetchExternalMarketDataAction::execute() call in
            // (UC-001業務ルール "外部データ取得は...特定銘柄の取得に失敗しても
            // 取込全体は失敗させない").
            app()->instance(MarketIndexClientInterface::class, new class implements MarketIndexClientInterface
            {
                public function fetchWeeklyHistory(string $indexName): array
                {
                    throw new \RuntimeException('市場指数取得に失敗');
                }
            });

            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertSuccessful();
            $this->assertDatabaseHas('import_batches', ['status' => 'completed']);
        });
    });

    describe('バリデーションエラー・境界値', function () {
        test('CSVファイルを1つも選択していない場合は422エラーになる', function () {
            $response = ucFrom001TestSubmit($this, []);

            $response->assertStatus(422);
            // UC-001エラーケース表:「ファイル未選択」は「一方のみアップロード」とは
            // 異なるメッセージ（CSVファイルを選択してください）を返す。
            $response->assertJsonPath('errors.jp_stock_file.0', 'CSVファイルを選択してください');
            $response->assertJsonPath('errors.us_stock_file.0', 'CSVファイルを選択してください');
            $this->assertDatabaseCount('import_batches', 0);
        });

        test('国内株式CSVのみアップロードした場合は422エラーになる', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath('errors.us_stock_file.0', '国内株式・米国株式のCSVは両方アップロードしてください');
            $this->assertDatabaseCount('import_batches', 0);
        });

        test('米国株式CSVのみアップロードした場合は422エラーになる', function () {
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath('errors.jp_stock_file.0', '国内株式・米国株式のCSVは両方アップロードしてください');
            $this->assertDatabaseCount('import_batches', 0);
        });

        test('拡張子が.csv以外のファイルをアップロードした場合は422エラーになる', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.txt', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertStatus(422);
            $this->assertDatabaseCount('import_batches', 0);
        });

        test('ファイルサイズが5MBを超える場合は413エラーになる', function () {
            $oversizedJp = UploadedFile::fake()->create('jp_stock.csv', 5121, 'text/csv'); // > 5MB
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => $oversizedJp,
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertStatus(413);
            $this->assertDatabaseCount('import_batches', 0);
        });

        test('ファイル全体がパース不能な場合は取込全体を失敗として扱い422エラーになる', function () {
            // Deliberately not valid Shift-JIS and not shaped like the
            // expected 楽天証券 column structure at all.
            $unparseableJp = "\xFF\xFE\x00\x01\x02\x03BROKEN_NOT_A_VALID_CSV_STRUCTURE_\xDE\xAD\xBE\xEF\r\n".
                "\xFF\xFE\x00\x01\x02\x03BROKEN_NOT_A_VALID_CSV_STRUCTURE_\xDE\xAD\xBE\xEF\r\n";
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = ucFrom001TestSubmit($this, [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', $unparseableJp),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ]);

            $response->assertStatus(422);

            $batch = DB::table('import_batches')->latest('id')->first();
            expect($batch)->not->toBeNull();
            expect($batch->status)->toBe('failed');
            expect($batch->failure_reason)->not->toBeNull();
        });
    });

    describe('権限', function () {
        test('未認証ユーザーはCSV取込を実行できない', function () {
            $jpCsv = ucFrom001TestJpStockCsv([
                ['code' => '7203', 'name' => 'トヨタ自動車', 'quantity' => '10', 'avg_cost' => '2,000.00', 'current_price' => '2,500.0'],
            ]);
            $usCsv = ucFrom001TestUsStockCsv([
                ['ticker' => 'AAPL', 'name' => 'アップル', 'quantity' => '5', 'avg_cost' => '100.00', 'current_price' => '150.00'],
            ]);

            $response = $this->post('/csv-import', [
                'jp_stock_file' => ucFrom001TestFakeCsvFile('jp_stock.csv', ucFrom001TestUtf8ToShiftJis($jpCsv)),
                'us_stock_file' => ucFrom001TestFakeCsvFile('us_stock.csv', ucFrom001TestUtf8ToShiftJis($usCsv)),
            ], ['Accept' => 'application/json']);

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase.
            expect($response->status())->toBeIn([302, 401, 403]);
            $this->assertDatabaseCount('import_batches', 0);
        });
    });
});
