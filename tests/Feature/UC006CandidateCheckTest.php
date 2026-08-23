<?php

namespace Tests\Feature;

use App\Models\FinancialStatement;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use App\Models\WatchRecord;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-006: 新規投資候補の重複チェック（Cycle B 本体） — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-006 基本フロー・入力/出力表・業務ルール・
|     エラーケース。UC-003〔銘柄詳細表示〕の指標セットを流用する旨の記述含む)
|   - docs/architecture/data-model.md (watch_records / financial_statements /
|     holdings / holding_snapshots / technical_indicators /
|     fundamental_indicators / sector_classifications)
|   - app/Actions/Holding/ShowHoldingDetailAction.php (UC-003, 既にGreen。
|     テクニカル/ファンダメンタルズ指標のnull-safe取得パターン
|     `$indicator->field ?? null` をそのまま踏襲する)
|   - app/Services/Sector/SectorAllocationCalculator.php (UC-005, 既にGreen。
|     overlap_rate/diversification_commentの算出に「新規計算式を作らない」
|     方針〔use-cases.md業務ルール〕で流用する)
|   - app/Models/HoldingMemo.php + 2026_08_19_000000_create_holding_memos_table.php
|     (追記のみ・$timestamps=false のパターンを WatchRecord にもそのまま適用)
|
| Cycle A (financial_statements write path) is already Green: App\Models\
| FinancialStatement and the financial_statements table already exist and
| are populated by FetchExternalMarketDataAction for JP stocks. This Cycle B
| test file builds on top of that but everything it directly needs is still
| entirely unimplemented:
|   - No `watch_records` migration and no App\Models\WatchRecord class at
|     all. Any test that arranges pre-existing watch history via the (not
|     yet existing) WatchRecord model is expected to fatal-error with a
|     "class not found" error during Arrange — same convention as
|     UC003HoldingDetailTest.php did for HoldingMemo before it existed.
|   - No Controller, Route, FormRequest, or Action for either
|     `GET /candidate-check` or `POST /candidate-check/watch-records`, so
|     every HTTP-level test is expected to fail with a 404 (route not
|     found) for tests that don't touch WatchRecord during Arrange — same
|     convention as UC004SignalListTest.php / UC005SectorDashboardTest.php /
|     UC008NewCandidateListTest.php (Red state caused purely by "no route
|     yet", not by a typo/setup bug).
|   - No app/Services/Candidate/CandidateOverlapCalculator.php (its
|     behavior is only exercised indirectly through the GET endpoint here).
|
| Assumptions made while writing these tests (NOT yet confirmed by an
| implementation — flag during Gate 4 review; a different contract may be
| chosen instead):
|   - Endpoints: `GET /candidate-check?symbol_code=XXXX` (query string, not
|     a route param — matches use-cases.md's flat input table which lists
|     symbol_code/watch_status/watch_memo together rather than treating
|     symbol_code as a resource identifier) and
|     `POST /candidate-check/watch-records` (request body carries
|     symbol_code/watch_status/watch_memo), both behind `auth` middleware,
|     same single-user "web" session guard convention as UC-001〜UC-005.
|   - Success response body shape for GET: `{"data": {symbol_code,
|     symbol_name, sector, overlap_rate, diversification_comment, rsi, macd,
|     bollinger_band: {bb_upper, bb_lower}, ma20, ma75, volume, volume_ma20,
|     week52_high, week52_low, relative_strength_vs_market,
|     relative_strength_vs_sector, per, pbr, roe, revenue_growth,
|     equity_ratio, dividend_yield, eps_growth, peg_ratio,
|     historical_performance, watch_status, watch_memo_history}}` — the
|     exact same technical/fundamental indicator field set and null-safe
|     handling as ShowHoldingDetailAction (UC-003), per use-cases.md's
|     explicit "UC-003と同一項目" instruction. Deliberately EXCLUDES
|     `operating_income_growth`/`dividend_payout_ratio` even though those
|     columns exist on fundamental_indicators, because UC-003's own output
|     table (and ShowHoldingDetailAction) already excludes them.
|   - **`overlap_rate`/`diversification_comment` computation is THE central
|     Gate4-confirmation point of this whole file**: use-cases.md only says
|     "重複度は「対象銘柄と同一セクターの既存保有比率」を基準に算出する" and
|     "新規計算式を作らない" (reuse SectorAllocationCalculator, UC-005). This
|     test assumes:
|       1. Resolve the candidate's own sector name the same way
|          SectorAllocationCalculator does internally
|          (`sectorClassification->name ?? '未分類'`).
|       2. Call SectorAllocationCalculator::calculate() and find the row
|          whose `sector_name` matches.
|       3. If found: `overlap_rate` = that row's `allocation_rate`, and
|          `diversification_comment` is a fixed string keyed off that row's
|          `allocation_status`:
|            - '健全'     -> 'このセクターへの追加投資は分散の観点で問題ありません'
|            - 'やや偏り'  -> 'このセクターの保有比率はやや高めです。追加投資は慎重に検討してください'
|            - '偏り警告'  -> 'このセクターの保有比率は既に高い状態です。新規投資は分散の観点で推奨されません'
|       4. If no row matches (no current holdings at all in that sector) ->
|          `overlap_rate = 0.0`,
|          `diversification_comment = '現在このセクターの保有はありません。新規投資は分散に貢献します'`.
|     This wording/behavior is a best-guess placeholder — please confirm or
|     correct at Gate 4 (same spirit as NewCandidateFinder's 1%/2%
|     ambiguity resolution earlier in this project).
|   - `historical_performance` is built from `financial_statements` rows
|     for the holding, ordered by `fiscal_period` DESC, each item
|     `{fiscal_period, revenue, operating_income, revenue_yoy_change,
|     operating_income_yoy_change}` (data-model.md's column names carried
|     through verbatim); empty array if no rows exist yet.
|   - `watch_status` in the GET response is the most recent WatchRecord's
|     `watch_status` for the holding (by `recorded_at` DESC, `id` DESC
|     tiebreak), or `null` if no WatchRecord exists yet for this holding.
|   - `watch_memo_history` is ALL WatchRecord rows for the holding, ordered
|     `recorded_at` DESC then `id` DESC, each item `{recorded_at,
|     watch_status, memo}`; empty array if none exist.
|   - `symbol_code` must already exist in `holdings` for BOTH endpoints
|     (validated via a FormRequest rule, e.g. `Rule::exists('holdings',
|     'symbol_code')`) — an unknown symbol_code is a 422 "銘柄コードを確認
|     してください" per use-cases.md's error table, NOT a controller-level
|     find-or-create (this app has no existing Controller-level exception
|     handler for that pattern elsewhere, so a FormRequest validation rule
|     fits the established convention better).
|   - POST success response: 201, body `{"data": {"watch_status": ...,
|     "memo": ..., "recorded_at": ...}}` for the newly created WatchRecord
|     row (mirrors HoldingDetailController::storeMemo's response shape,
|     adapted to WatchRecord's own columns).
|   - **Gate4-assumption**: when BOTH `watch_status` and `watch_memo` are
|     omitted from the POST body (nothing meaningful to persist), this test
|     assumes a 422 response, since use-cases.md does not explicitly define
|     this case and there would be nothing to append to the history log.
|     Please confirm this behavior (or a different one, e.g. silently
|     no-op / 200) at Gate 4.
|   - decimal-cast numeric fields are compared as floats regardless of
|     whether the JSON encodes them as string or number (same convention as
|     UC002/UC003/UC004/UC005/UC008).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom006CandidateTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom006CandidateTestHolding(array $attributes = []): Holding
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
function ucFrom006CandidateTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
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

function ucFrom006CandidateTestSector(string $name): SectorClassification
{
    return SectorClassification::query()->firstOrCreate(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom006CandidateTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 55.5,
        'macd' => 0.5432,
        'macd_signal' => 0.4321,
        'ma20' => 2400.0,
        'ma75' => 2300.0,
        'bb_upper' => 2600.0,
        'bb_lower' => 2200.0,
        'volume' => 1_100_000,
        'volume_ma20' => 900_000,
        'week52_high' => 2900.0,
        'week52_low' => 1800.0,
        'relative_strength_vs_market' => 2.1,
        'relative_strength_vs_sector' => -0.5,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom006CandidateTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 14.2,
        'pbr' => 1.1,
        'roe' => 11.5,
        'revenue_growth' => 7.4,
        'operating_income_growth' => 5.1,
        'equity_ratio' => 48.0,
        'dividend_yield' => 2.4,
        'dividend_payout_ratio' => 28.0,
        'eps_growth' => 9.4,
        'peg_ratio' => 1.51,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom006CandidateTestFinancialStatement(Holding $holding, array $attributes = []): FinancialStatement
{
    return FinancialStatement::create(array_merge([
        'holding_id' => $holding->id,
        'fiscal_period' => '2025Q4',
        'revenue' => 100_000_000.00,
        'operating_income' => 10_000_000.00,
        'eps' => 120.50,
        'revenue_yoy_change' => 5.5,
        'operating_income_yoy_change' => 3.2,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * `watch_records` has no migration and App\Models\WatchRecord does not
 * exist yet (docs/architecture/data-model.md#watch_records). Calling this
 * helper is expected to fatal-error with a "class not found" error until
 * Gate 4 Green work adds the model/migration — that is the intended Red
 * state for any test that uses it.
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom006CandidateTestWatchRecord(Holding $holding, array $attributes = []): object
{
    return WatchRecord::create(array_merge([
        'holding_id' => $holding->id,
        'watch_status' => '様子見',
        'memo' => null,
        'recorded_at' => now(),
    ], $attributes));
}

/**
 * Fetch the candidate-check view as an authenticated user.
 */
function ucFrom006CandidateTestFetch(TestCase $test, string $symbolCode, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();
    $url = '/api/candidate-check?'.http_build_query(['symbol_code' => $symbolCode]);

    return $test->actingAs($user)->getJson($url);
}

/**
 * Save a watch status/memo record as an authenticated user.
 *
 * @param  array<string, mixed>  $body
 */
function ucFrom006CandidateTestSaveWatchRecord(TestCase $test, array $body, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->postJson('/api/candidate-check/watch-records', $body);
}

describe('UC-006: 新規投資候補の重複チェック（本体）', function () {
    describe('正常系（重複チェック取得）', function () {
        test('保有中銘柄の重複チェックでoverlap_rate・diversification_comment・指標一式・historical_performance・watch_status・watch_memo_historyが返る', function () {
            [, $snapshot] = ucFrom006CandidateTestImportBatch();

            // 半導体セクター: 100株×4,500円 = 450,000円 (45% -> やや偏り)。
            // UC-005テストと同じ配分を再利用し、SectorAllocationCalculatorの
            // 流用であることを検証する。
            $semiconductorSector = ucFrom006CandidateTestSector('半導体');
            $target = ucFrom006CandidateTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $target, [
                'quantity' => 100,
                'current_price' => 4500.00,
            ]);

            // 自動車セクター: 100株×3,000円 = 300,000円 (30%)
            $autoSector = ucFrom006CandidateTestSector('自動車');
            $autoHolding = ucFrom006CandidateTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 100,
                'current_price' => 3000.00,
            ]);

            // 未分類: 250,000円 (25%)
            $unclassifiedHolding = ucFrom006CandidateTestHolding([
                'symbol_code' => '9999',
                'symbol_name' => 'その他保有株',
                'sector_classification_id' => null,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $unclassifiedHolding, [
                'quantity' => 250,
                'current_price' => 1000.00,
            ]);
            // Total = 450,000 + 300,000 + 250,000 = 1,000,000

            ucFrom006CandidateTestTechnicalIndicator($target, ['rsi' => 60.0]);
            ucFrom006CandidateTestFundamentalIndicator($target, ['per' => 18.0]);

            ucFrom006CandidateTestFinancialStatement($target, [
                'fiscal_period' => '2025Q3',
                'revenue' => 90_000_000.00,
                'operating_income' => 8_000_000.00,
            ]);
            ucFrom006CandidateTestFinancialStatement($target, [
                'fiscal_period' => '2025Q4',
                'revenue' => 100_000_000.00,
                'operating_income' => 10_000_000.00,
            ]);

            ucFrom006CandidateTestWatchRecord($target, [
                'watch_status' => '様子見',
                'memo' => '初回チェック時のメモ',
                'recorded_at' => now()->subDays(2),
            ]);
            ucFrom006CandidateTestWatchRecord($target, [
                'watch_status' => '買い時',
                'memo' => '押し目を確認、買い増し検討',
                'recorded_at' => now()->subDay(),
            ]);

            $response = ucFrom006CandidateTestFetch($this, '6920');

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['symbol_code'])->toBe('6920');
            expect($data['symbol_name'])->toBe('レーザーテック');
            expect($data['sector'])->toBe('半導体');

            // Gate4要確認の仮定 — 詳細はファイル冒頭コメント参照。
            expect((float) $data['overlap_rate'])->toEqualWithDelta(45.0, 0.5);
            expect($data['diversification_comment'])->toBe('このセクターの保有比率はやや高めです。追加投資は慎重に検討してください');

            expect((float) $data['rsi'])->toEqualWithDelta(60.0, 0.01);
            expect((float) $data['bollinger_band']['bb_upper'])->toEqualWithDelta(2600.0, 0.01);
            expect((float) $data['bollinger_band']['bb_lower'])->toEqualWithDelta(2200.0, 0.01);
            expect((float) $data['per'])->toEqualWithDelta(18.0, 0.01);

            $historicalPerformance = $data['historical_performance'];
            expect($historicalPerformance)->toHaveCount(2);
            expect($historicalPerformance[0]['fiscal_period'])->toBe('2025Q4');
            expect((float) $historicalPerformance[0]['revenue'])->toEqualWithDelta(100_000_000.0, 0.01);
            expect((float) $historicalPerformance[0]['operating_income'])->toEqualWithDelta(10_000_000.0, 0.01);
            expect($historicalPerformance[1]['fiscal_period'])->toBe('2025Q3');

            expect($data['watch_status'])->toBe('買い時');

            $watchMemoHistory = $data['watch_memo_history'];
            expect($watchMemoHistory)->toHaveCount(2);
            expect($watchMemoHistory[0]['watch_status'])->toBe('買い時');
            expect($watchMemoHistory[0]['memo'])->toBe('押し目を確認、買い増し検討');
            expect($watchMemoHistory[1]['watch_status'])->toBe('様子見');
            expect($watchMemoHistory[1]['memo'])->toBe('初回チェック時のメモ');
        });

        test('候補（未保有）銘柄でも同一セクターの既存保有比率からoverlap_rate・diversification_commentが返る', function () {
            [, $snapshot] = ucFrom006CandidateTestImportBatch();

            // ヘルスケアセクター: 既存保有100株×3,000円=300,000円のみ (30% -> 健全)。
            $healthcareSector = ucFrom006CandidateTestSector('ヘルスケア');
            $existingHolding = ucFrom006CandidateTestHolding([
                'symbol_code' => '4568',
                'symbol_name' => '第一三共',
                'sector_classification_id' => $healthcareSector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $existingHolding, [
                'quantity' => 100,
                'current_price' => 3000.00,
            ]);

            // 他セクター保有: 700,000円 (別セクターであり候補には無関係)
            $autoSector = ucFrom006CandidateTestSector('自動車');
            $autoHolding = ucFrom006CandidateTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 700,
                'current_price' => 1000.00,
            ]);
            // Total = 300,000 + 700,000 = 1,000,000 -> ヘルスケア = 30%

            // 候補銘柄自体は保有していない（holding_snapshotsを作らないfind-or-create相当）。
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
                'sector_classification_id' => $healthcareSector->id,
            ]);

            $response = ucFrom006CandidateTestFetch($this, $candidate->symbol_code);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['symbol_code'])->toBe('4589');
            expect($data['sector'])->toBe('ヘルスケア');

            // Gate4要確認の仮定 — 詳細はファイル冒頭コメント参照。
            expect((float) $data['overlap_rate'])->toEqualWithDelta(30.0, 0.5);
            expect($data['diversification_comment'])->toBe('このセクターへの追加投資は分散の観点で問題ありません');

            // 未保有のため過去のウォッチ記録はまだ無い。
            expect($data['watch_status'])->toBeNull();
            expect($data['watch_memo_history'])->toBe([]);
        });
    });

    describe('指標・業績データの欠落パターン', function () {
        test('テクニカル/ファンダメンタルズ指標行が無い候補銘柄でも該当フィールドはnullで返り、overlap_rate等は通常通り返る', function () {
            [, $snapshot] = ucFrom006CandidateTestImportBatch();

            // 単独保有で100% -> 偏り警告。指標行はあえて作らない。
            $sector = ucFrom006CandidateTestSector('半導体');
            $target = ucFrom006CandidateTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $target, [
                'quantity' => 100,
                'current_price' => 1000.00,
            ]);
            // Deliberately no TechnicalIndicator/FundamentalIndicator rows created.

            $response = ucFrom006CandidateTestFetch($this, '6920');

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['rsi'])->toBeNull();
            expect($data['macd'])->toBeNull();
            expect($data['bollinger_band']['bb_upper'])->toBeNull();
            expect($data['bollinger_band']['bb_lower'])->toBeNull();
            expect($data['ma20'])->toBeNull();
            expect($data['volume'])->toBeNull();
            expect($data['week52_high'])->toBeNull();
            expect($data['relative_strength_vs_market'])->toBeNull();
            expect($data['per'])->toBeNull();
            expect($data['roe'])->toBeNull();
            expect($data['eps_growth'])->toBeNull();
            expect($data['peg_ratio'])->toBeNull();

            // 指標が無くても重複度判定自体は通常通り機能する。
            expect((float) $data['overlap_rate'])->toEqualWithDelta(100.0, 0.5);
            expect($data['diversification_comment'])->toBe('このセクターの保有比率は既に高い状態です。新規投資は分散の観点で推奨されません');
        });

        test('financial_statementsが1件も無い銘柄はhistorical_performanceが空リストになる', function () {
            [, $snapshot] = ucFrom006CandidateTestImportBatch();

            $sector = ucFrom006CandidateTestSector('自動車');
            $target = ucFrom006CandidateTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $target, [
                'quantity' => 100,
                'current_price' => 2000.00,
            ]);
            ucFrom006CandidateTestTechnicalIndicator($target);
            ucFrom006CandidateTestFundamentalIndicator($target);
            // Deliberately no FinancialStatement rows created.

            $response = ucFrom006CandidateTestFetch($this, '7203');

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['historical_performance'])->toBe([]);
            // 業績データが無くても指標自体は通常通り返る。
            expect($data['rsi'])->not->toBeNull();
        });

        test('同一セクターの既存保有が無いセクターの候補銘柄はoverlap_rate=0・対応するコメントになる', function () {
            [, $snapshot] = ucFrom006CandidateTestImportBatch();

            // 既存保有は自動車セクターのみ（他セクターの存在自体は必須では
            // ないが、ポートフォリオ評価総額を0にしないために用意する）。
            $autoSector = ucFrom006CandidateTestSector('自動車');
            $autoHolding = ucFrom006CandidateTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom006CandidateTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 100,
                'current_price' => 2000.00,
            ]);

            // 候補銘柄はセクター分類が未設定（未分類）で、他に未分類の既存
            // 保有も存在しない。
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '9984',
                'symbol_name' => 'ソフトバンクグループ',
                'sector_classification_id' => null,
            ]);

            $response = ucFrom006CandidateTestFetch($this, '9984');

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['sector'])->toBe('未分類');

            // Gate4要確認の仮定 — 詳細はファイル冒頭コメント参照。
            expect((float) $data['overlap_rate'])->toEqualWithDelta(0.0, 0.01);
            expect($data['diversification_comment'])->toBe('現在このセクターの保有はありません。新規投資は分散に貢献します');
        });
    });

    describe('正常系（ウォッチステータス・メモ保存）', function () {
        test('watch_statusのみ（メモなし）の保存が成功する', function () {
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $response = ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
                'watch_status' => '様子見',
            ]);

            $response->assertCreated();

            $data = $response->json('data');
            expect($data['watch_status'])->toBe('様子見');
            expect($data['memo'])->toBeNull();
            expect($data)->toHaveKey('recorded_at');

            $detail = ucFrom006CandidateTestFetch($this, $candidate->symbol_code);
            expect($detail->json('data.watch_status'))->toBe('様子見');
        });

        test('複数回保存すると過去の記録は編集されず追記され、GETのwatch_statusは最新1件・watch_memo_historyは全件新しい順になる', function () {
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
                'watch_status' => '様子見',
                'watch_memo' => '1回目のメモ',
            ])->assertCreated();

            ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
                'watch_status' => '買い時',
            ])->assertCreated();

            ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
                'watch_status' => '次回購入候補',
                'watch_memo' => '3回目のメモ',
            ])->assertCreated();

            $detail = ucFrom006CandidateTestFetch($this, $candidate->symbol_code);
            $detail->assertSuccessful();

            $data = $detail->json('data');
            expect($data['watch_status'])->toBe('次回購入候補');

            $history = $data['watch_memo_history'];
            expect($history)->toHaveCount(3);

            // recorded_at DESC / id DESC = 挿入順の逆順（最新が先頭）。
            expect($history[0]['watch_status'])->toBe('次回購入候補');
            expect($history[0]['memo'])->toBe('3回目のメモ');
            expect($history[1]['watch_status'])->toBe('買い時');
            expect($history[1]['memo'])->toBeNull();
            expect($history[2]['watch_status'])->toBe('様子見');
            expect($history[2]['memo'])->toBe('1回目のメモ');
        });
    });

    describe('異常系・境界値', function () {
        test('存在しないsymbol_codeを指定するとGET /candidate-checkは422になる', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->getJson('/api/candidate-check?'.http_build_query(['symbol_code' => '0000']));

            $response->assertStatus(422);
            $response->assertJsonPath('errors.symbol_code.0', '銘柄コードを確認してください');
        });

        test('存在しないsymbol_codeを指定するとPOST /candidate-check/watch-recordsは422になる', function () {
            $response = ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => '0000',
                'watch_status' => '様子見',
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath('errors.symbol_code.0', '銘柄コードを確認してください');
        });

        test('watch_memoが2000文字を超える場合は422になる', function () {
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $tooLongMemo = str_repeat('あ', 2001);

            $response = ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
                'watch_memo' => $tooLongMemo,
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath('errors.watch_memo.0', 'メモは2000文字以内で入力してください');
        });

        test('symbol_codeを指定せずにGET /candidate-checkを呼ぶと422になる', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->getJson('/api/candidate-check');

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['symbol_code']);
        });

        test('symbol_codeを指定せずにPOST /candidate-check/watch-recordsを呼ぶと422になる', function () {
            $response = ucFrom006CandidateTestSaveWatchRecord($this, [
                'watch_status' => '様子見',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['symbol_code']);
        });

        test('watch_statusに許可されていない値を指定すると422になる', function () {
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $response = ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
                'watch_status' => '謎ステータス',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['watch_status']);
        });

        test('watch_status・watch_memoの両方が省略された場合は422になる（Gate4要確認の仮定）', function () {
            // use-cases.mdはこのケースを明示的には扱っていない。保存すべき
            // 内容が何も無いため422を仮定しているが、Gate4で正式な仕様を
            // 確認する必要がある（ファイル冒頭コメント参照）。
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $response = ucFrom006CandidateTestSaveWatchRecord($this, [
                'symbol_code' => $candidate->symbol_code,
            ]);

            $response->assertStatus(422);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーはGET /candidate-checkを取得できない', function () {
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $response = $this->getJson('/api/candidate-check?'.http_build_query(['symbol_code' => $candidate->symbol_code]));

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001〜UC-005).
            expect($response->status())->toBeIn([302, 401, 403]);
        });

        test('未認証ユーザーはPOST /candidate-check/watch-recordsを保存できない', function () {
            $candidate = ucFrom006CandidateTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $response = $this->postJson('/api/candidate-check/watch-records', [
                'symbol_code' => $candidate->symbol_code,
                'watch_status' => '様子見',
            ]);

            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
