<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-009: Import summary report — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-009)
|   - docs/product/requirements.md (F-009, 6章, 7章)
|   - docs/architecture/data-model.md (import_summary_reports /
|     import_summary_report_items, "保留・確定が必要な初期パラメータ値")
|   - docs/adr/ADR-0002-nisa-account-type-tracking.md
|   - docs/adr/ADR-0003-f009-scoring-transparency-relaxation.md
|
| Nothing under app/ implements the report *generation* logic yet.
| `ImportCsvAction` only creates a stub `import_summary_reports` row with a
| fixed headline (`sprintf('%d件の保有銘柄を取り込みました。', ...)`); it never
| creates `import_summary_report_items` rows, and there is no
| App\Models\ImportSummaryReportItem class, no `import_summary_report_items`
| migration, no read endpoint/controller, and no route registered for it in
| routes/web.php. Every test below is therefore expected to fail — either
| with a 404 (route not found) or with an assertion mismatch against the
| stub headline — which is the intended Red state, not a typo.
|
| In addition, App\Models\WatchedTheme and the `watched_themes` table do not
| exist yet at all (no migration). The one test that exercises the
| "新規投資候補" recommendation type is therefore expected to fatal-error
| with a "class not found" error during Arrange, rather than during
| Act/Assert — same intentional-Red convention as UC002HoldingListTest.php /
| UC003HoldingDetailTest.php did for tables that didn't exist yet at the
| time those tests were written.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred). See also the completion report's "実装未確定事項" list for
| points that were deliberately left *out* of these tests rather than
| encoded as an assumption here.
|
|   - Endpoint: GET /import-batches/{importBatch}/summary-report, route
|     model binding on import_batches.id, `auth` middleware (same "web"
|     session guard convention as UC-001/002/003). The alternative of
|     folding the report into UC-001's own POST /csv-import response body
|     was considered but a dedicated GET endpoint was chosen so the report
|     can also be re-fetched later without re-uploading CSVs. Flag at Gate 4
|     if UC-001's response should embed this instead (or in addition).
|   - Report *semantics*: because these tests never invoke the real
|     ImportCsvAction/CSV parsing pipeline (arranging fake CSV bytes for a
|     scoring-only test would over-couple this file to UC-001's parser),
|     each test seeds the underlying tables directly (import_batches /
|     snapshots / holdings / holding_snapshots / sector_classifications /
|     technical_indicators / fundamental_indicators) exactly like
|     UC002HoldingListTest.php / UC003HoldingDetailTest.php do, and then
|     calls the GET endpoint. This assumes the endpoint (re)computes the
|     report from the *current* state of the batch's snapshot at request
|     time (or that generation is triggered as a side effect of import and
|     always reflects the latest underlying data for that batch) rather
|     than strictly replaying a frozen row that could only ever have been
|     written once by a prior real ImportCsvAction run. Flag at Gate 4 if
|     the intended design is "write-once at import time only, unreadable /
|     stale otherwise".
|   - Success response body shape: `{"data": {portfolio_headline,
|     generated_at, top_recommendations: [...],
|     supplementary_recommendations: [...]}}`, matching use-cases.md's
|     output table field names verbatim (not the DB column names — e.g.
|     `target` per use-cases.md, not `target_label` per data-model.md).
|     Each recommendation item is assumed to be
|     `{rank, recommendation_type, target, action_suggestion,
|     reason_summary, link_to}`, with supplementary items additionally
|     carrying `is_supplementary: true`. Top items are not asserted to
|     either include or omit `is_supplementary` (data-model.md only
|     requires it on the supplementary side).
|   - `link_to` values: `'UC-003'` for `利確検討` items (only one candidate
|     screen is named in use-cases.md's basic flow step 6) and `'UC-005'`
|     for `リバランス` items (ditto). For `新規投資候補` items, use-cases.md
|     names two possible screens (`UC-006`/`UC-008`, which share one
|     physical screen per UC-006 業務ルール), so this test only asserts
|     `link_to` is one of `['UC-006', 'UC-008']` rather than a single exact
|     value.
|   - `新規投資候補` matching mechanism: UC-008/UC-009 leave "how a holding
|     is matched against a registered watched theme" unspecified beyond
|     "登録済みテーマへの銘柄の機械的な合致判定". This test assumes the
|     simplest interpretation — a candidate holding's
|     `sector_classifications.name` equals a `watched_themes.name` exactly
|     — combined with the financial-health draft filter from
|     data-model.md's "保留・確定が必要な初期パラメータ値" table
|     (equity_ratio >= 40, roe >= 10). **This is the single biggest open
|     question in this file** — please confirm or correct the matching rule
|     at Gate 4 (same weight of caution as UC003's signal_result/
|     signal_reason wording assumption).
|   - Priority ordering (which candidate ranks higher) is only asserted in
|     the loosest possible way: given two clearly-more-vs-less-extreme
|     candidates of the *same* recommendation type, the more extreme one is
|     assumed to receive a numerically smaller `rank`. The composite score
|     formula/weights themselves (data-model.md: "初期パラメータ値", not yet
|     finalized) are never asserted.
|   - Item count region (10 top / 20 total) uses data-model.md's draft
|     values directly. If Gate 4 changes these numbers, this file's
|     count-based tests need updating accordingly.
|   - NISA account-type exclusion (ADR-0002) is *not* tested in this file at
|     all — `holding_snapshot_accounts` has no migration/model yet, and
|     UC-009's own business rules don't explicitly restate the NISA
|     exclusion the way UC-004/UC-005 do. See the completion report's
|     "実装未確定事項" list.
|   - ADR-0004 signal reflection (new, added after this file's initial Green
|     merge): `buildTakeProfitCandidates()` currently only reads
|     `unrealized_gain_rate` and `holding->technicalIndicator->rsi` — it
|     never queries the `signals` table populated by
|     App\Services\Analysis\SignalDeterminationService /
|     App\Actions\Analysis\FetchExternalMarketDataAction (UC-004's 7 signal
|     types, docs/architecture/data-model.md#signals). The one new test
|     below assumes (a) `reason_summary` for a 利確検討 candidate must
|     include wording drawn from that candidate's saved
|     `signals.reason_summary` rows when any exist, and (b) having more
|     saved signals raises a candidate's rank relative to an
|     otherwise-comparable candidate with none — but does *not* assert the
|     exact scoring formula/weight (same black-box relaxation as ADR-0003).
|     Confirm both the exact reason_summary-composition rule and the
|     signal-count-to-score weighting at Gate 4.
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
        'rsi' => 70.0,
        'macd' => null,
        'macd_signal' => null,
        'ma20' => null,
        'ma75' => null,
        'bb_upper' => null,
        'bb_lower' => null,
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
 * `watched_themes` has no migration and App\Models\WatchedTheme does not
 * exist yet (docs/architecture/data-model.md, UC-008). Calling this helper
 * is expected to fatal-error with a "class not found" error until Gate 4
 * Green work adds the model/migration — that is the intended Red state for
 * the one test that uses it.
 */
function ucFrom009TestWatchedTheme(string $name): object
{
    return \App\Models\WatchedTheme::create(['name' => $name]);
}

/**
 * Fetch the UC-009 summary report as an authenticated user.
 */
function ucFrom009TestFetchReport(TestCase $test, int|ImportBatch $importBatch, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();
    $importBatchId = $importBatch instanceof ImportBatch ? $importBatch->id : $importBatch;

    return $test->actingAs($user)->getJson("/import-batches/{$importBatchId}/summary-report");
}

/**
 * Seed `$count` individually-qualifying UC-004-style 利確検討 candidates
 * (含み益+20%超), each in its own sector so no single sector crosses the
 * UC-005 70%偏り警告 threshold and pollutes the ranking pool with an
 * unwanted リバランス item. Gain rate and RSI are varied per candidate so
 * composite scores are very unlikely to tie.
 */
function ucFrom009TestSeedManyTakeProfitCandidates(Snapshot $snapshot, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $sector = ucFrom009TestSectorClassification("テストセクター{$i}", sprintf('%03d', $i));
        $holding = ucFrom009TestHolding([
            'symbol_code' => sprintf('90%02d', $i),
            'market' => 'jp',
            'symbol_name' => "テスト銘柄{$i}",
            'sector_classification_id' => $sector->id,
        ]);

        $gainRate = 21.0 + $i; // 21%〜(21+count-1)%, all comfortably over the 20% threshold
        $averageCost = 1000.0;
        $currentPrice = $averageCost * (1 + $gainRate / 100);
        $quantity = 10;

        ucFrom009TestHoldingSnapshot($snapshot, $holding, [
            'quantity' => $quantity,
            'average_cost' => $averageCost,
            'current_price' => $currentPrice,
            'unrealized_gain_amount' => ($currentPrice - $averageCost) * $quantity,
            'unrealized_gain_rate' => $gainRate,
        ]);

        ucFrom009TestTechnicalIndicator($holding, ['rsi' => 60.0 + $i]);
    }
}

describe('UC-009: 取込後サマリーレポート', function () {
    describe('正常系（レポート生成・基本構造）', function () {
        test('取込後サマリーレポートを取得できる（全体感サマリー・生成日時・主要/補足レコメンドが返る）', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            $holding = ucFrom009TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            ucFrom009TestHoldingSnapshot($snapshot, $holding, [
                'average_cost' => 1000.0,
                'current_price' => 1300.0,
                'unrealized_gain_amount' => 3000.0,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom009TestTechnicalIndicator($holding, ['rsi' => 75.0]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['portfolio_headline'])->toBeString();
            expect(trim((string) $data['portfolio_headline']))->not->toBe('');
            expect($data['generated_at'])->not->toBeNull();
            expect($data['top_recommendations'])->toBeArray();
            expect($data['supplementary_recommendations'])->toBeArray();
        });

        test('主要レコメンド項目がrank/recommendation_type/target/action_suggestion/reason_summary/link_toを持つ', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            $holding = ucFrom009TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            ucFrom009TestHoldingSnapshot($snapshot, $holding, [
                'average_cost' => 1000.0,
                'current_price' => 1300.0,
                'unrealized_gain_amount' => 3000.0,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom009TestTechnicalIndicator($holding, ['rsi' => 75.0]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $top = $response->json('data.top_recommendations');
            expect($top)->toHaveCount(1);

            $item = $top[0];
            expect((int) $item['rank'])->toBe(1);
            expect($item['recommendation_type'])->toBe('利確検討');
            expect($item['target'])->toContain('7203');
            expect(trim((string) $item['action_suggestion']))->not->toBe('');
            expect(trim((string) $item['reason_summary']))->not->toBe('');
            // Placeholder contract — see file-level docblock. Confirm at Gate 4.
            expect($item['link_to'])->toBe('UC-003');
        });

        test('reason_summary・portfolio_headlineに判定の主要因となった代表指標が具体的な値とともに含まれる（ADR-0003）', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            $holding = ucFrom009TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            ucFrom009TestHoldingSnapshot($snapshot, $holding, [
                'average_cost' => 1000.0,
                'current_price' => 1380.0,
                'unrealized_gain_amount' => 3800.0,
                'unrealized_gain_rate' => 38.0,
            ]);
            ucFrom009TestTechnicalIndicator($holding, ['rsi' => 71.0]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            $reasonSummary = (string) $data['top_recommendations'][0]['reason_summary'];

            // ADR-0003: the composite score's weighting stays undisclosed, but
            // the *result* must not be a black box — reason_summary/
            // portfolio_headline must reference at least one concrete,
            // numeric representative indicator value (e.g. "含み益+38%",
            // "RSI71"), not just a generic sentence with no figures.
            expect(preg_match('/\d/', $reasonSummary))->toBe(1);
            expect(preg_match('/\d/', (string) $data['portfolio_headline']))->toBe(1);
        });
    });

    describe('正常系（優先順位付け・件数区分）', function () {
        test('候補が21件以上ある場合、主要レコメンドは10件・補足レコメンドは11〜20件目の10件に制限される', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedManyTakeProfitCandidates($snapshot, 25);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['top_recommendations'])->toHaveCount(10);
            expect($data['supplementary_recommendations'])->toHaveCount(10);

            $topRanks = collect($data['top_recommendations'])->pluck('rank')->map(fn ($r) => (int) $r)->sort()->values()->all();
            expect($topRanks)->toBe(range(1, 10));

            $supplementaryRanks = collect($data['supplementary_recommendations'])->pluck('rank')->map(fn ($r) => (int) $r)->sort()->values()->all();
            expect($supplementaryRanks)->toBe(range(11, 20));

            foreach ($data['supplementary_recommendations'] as $item) {
                expect($item['is_supplementary'])->toBeTrue();
            }

            $topTargets = collect($data['top_recommendations'])->pluck('target')->all();
            $supplementaryTargets = collect($data['supplementary_recommendations'])->pluck('target')->all();
            expect(array_intersect($topTargets, $supplementaryTargets))->toBe([]);
        });

        test('候補が11〜20件の場合、主要10件・補足はその残り件数のみになる', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedManyTakeProfitCandidates($snapshot, 15);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['top_recommendations'])->toHaveCount(10);
            expect($data['supplementary_recommendations'])->toHaveCount(5);
        });

        test('候補が10件未満の場合、存在する件数のみ主要レコメンドとして表示し補足レコメンドは空になる', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();
            ucFrom009TestSeedManyTakeProfitCandidates($snapshot, 4);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['top_recommendations'])->toHaveCount(4);
            expect($data['supplementary_recommendations'])->toBe([]);
        });

        test('より極端な指標（含み益率・RSI）を持つ候補ほどrankが小さくなる', function () {
            // Placeholder contract for relative ordering only — the exact
            // composite score formula/weights are intentionally not
            // asserted (data-model.md: 初期パラメータ値、未確定). Confirm the
            // ordering contract itself at Gate 4.
            [$batch, $snapshot] = ucFrom009TestImportBatch();

            $sectorA = ucFrom009TestSectorClassification('セクターA', 'A01');
            $sectorB = ucFrom009TestSectorClassification('セクターB', 'B01');

            $extremeHolding = ucFrom009TestHolding([
                'symbol_code' => '1111', 'market' => 'jp', 'symbol_name' => '極端銘柄',
                'sector_classification_id' => $sectorA->id,
            ]);
            ucFrom009TestHoldingSnapshot($snapshot, $extremeHolding, [
                'average_cost' => 1000.0,
                'current_price' => 1500.0,
                'unrealized_gain_amount' => 5000.0,
                'unrealized_gain_rate' => 50.0,
            ]);
            ucFrom009TestTechnicalIndicator($extremeHolding, ['rsi' => 85.0]);

            $borderlineHolding = ucFrom009TestHolding([
                'symbol_code' => '2222', 'market' => 'jp', 'symbol_name' => '境界銘柄',
                'sector_classification_id' => $sectorB->id,
            ]);
            ucFrom009TestHoldingSnapshot($snapshot, $borderlineHolding, [
                'average_cost' => 1000.0,
                'current_price' => 1210.0,
                'unrealized_gain_amount' => 2100.0,
                'unrealized_gain_rate' => 21.0,
            ]);
            ucFrom009TestTechnicalIndicator($borderlineHolding, ['rsi' => 55.0]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $top = collect($response->json('data.top_recommendations'));
            $extremeItem = $top->first(fn ($item) => str_contains((string) $item['target'], '1111'));
            $borderlineItem = $top->first(fn ($item) => str_contains((string) $item['target'], '2222'));

            expect($extremeItem)->not->toBeNull();
            expect($borderlineItem)->not->toBeNull();

            $extremeRank = (int) $extremeItem['rank'];
            $borderlineRank = (int) $borderlineItem['rank'];

            expect($extremeRank)->toBeLessThan($borderlineRank);
        });

        test('signalsテーブルに保存されたシグナルが利確検討のreason_summary・優先順位に反映される（ADR-0004）', function () {
            // Placeholder contract for signal reflection only — the exact
            // signal-count-to-score weighting is intentionally not asserted
            // (same black-box relaxation as ADR-0003, see the file-level
            // docblock's ADR-0004 note). Confirm at Gate 4.
            [$batch, $snapshot] = ucFrom009TestImportBatch();

            $signalSector = ucFrom009TestSectorClassification('セクターC', 'C01');
            $noSignalSector = ucFrom009TestSectorClassification('セクターD', 'D01');

            // Holding with 2 saved signals. Gain rate/RSI are kept slightly
            // *below* the no-signal holding's, so that the current
            // gain-rate-plus-RSI-only composite score would rank it *lower*
            // than the no-signal holding — the signals must be what tips the
            // ranking the other way once reflected.
            $signalHolding = ucFrom009TestHolding([
                'symbol_code' => '3333', 'market' => 'jp', 'symbol_name' => 'シグナル銘柄',
                'sector_classification_id' => $signalSector->id,
            ]);
            $signalHoldingSnapshot = ucFrom009TestHoldingSnapshot($snapshot, $signalHolding, [
                'average_cost' => 1000.0,
                'current_price' => 1300.0,
                'unrealized_gain_amount' => 3000.0,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom009TestTechnicalIndicator($signalHolding, ['rsi' => 70.0]);
            ucFrom009TestSignal($signalHoldingSnapshot, [
                'signal_type' => 'week52_high_pullback',
                'reason_summary' => '週52週高値から-15%まで反落',
            ]);
            ucFrom009TestSignal($signalHoldingSnapshot, [
                'signal_type' => 'peg_overvalued',
                'reason_summary' => 'PEGレシオが2.3で割高水準',
            ]);

            // Holding with no saved signals, gain rate/RSI set slightly
            // *higher* than the signal holding's.
            $noSignalHolding = ucFrom009TestHolding([
                'symbol_code' => '4444', 'market' => 'jp', 'symbol_name' => '無シグナル銘柄',
                'sector_classification_id' => $noSignalSector->id,
            ]);
            ucFrom009TestHoldingSnapshot($snapshot, $noSignalHolding, [
                'average_cost' => 1000.0,
                'current_price' => 1320.0,
                'unrealized_gain_amount' => 3200.0,
                'unrealized_gain_rate' => 32.0,
            ]);
            ucFrom009TestTechnicalIndicator($noSignalHolding, ['rsi' => 72.0]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $top = collect($response->json('data.top_recommendations'));
            $signalItem = $top->first(fn ($item) => str_contains((string) $item['target'], '3333'));
            $noSignalItem = $top->first(fn ($item) => str_contains((string) $item['target'], '4444'));

            expect($signalItem)->not->toBeNull();
            expect($noSignalItem)->not->toBeNull();

            // reason_summary must reflect the saved signals' content (ADR-0004),
            // not just gain rate/RSI — following this file's existing
            // convention of only checking for the presence of the driving
            // indicator's wording, not the full generated sentence.
            $signalReasonSummary = (string) $signalItem['reason_summary'];
            expect(
                str_contains($signalReasonSummary, '週52週高値')
                || str_contains($signalReasonSummary, 'PEG')
            )->toBeTrue();

            // Priority ordering only (composite_score's absolute value/weights
            // are not asserted, per this file's existing convention): the
            // signal-bearing holding must outrank the signal-less holding even
            // though its raw gain rate/RSI are slightly lower.
            $signalRank = (int) $signalItem['rank'];
            $noSignalRank = (int) $noSignalItem['rank'];

            expect($signalRank)->toBeLessThan($noSignalRank);
        });
    });

    describe('正常系（利確検討以外のレコメンド種別）', function () {
        test('セクター配分が70%以上に偏っている場合はリバランス提案が候補に含まれる', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();

            $overweightSector = ucFrom009TestSectorClassification('電気機器', '3650');
            $otherSector = ucFrom009TestSectorClassification('輸送用機器', '3750');

            foreach (['9001', '9002', '9003'] as $code) {
                $holding = ucFrom009TestHolding([
                    'symbol_code' => $code, 'market' => 'jp', 'symbol_name' => "偏りテスト銘柄{$code}",
                    'sector_classification_id' => $overweightSector->id,
                ]);
                // Gain kept well under the 20% 利確検討 threshold so this
                // scenario isolates the リバランス path.
                ucFrom009TestHoldingSnapshot($snapshot, $holding, [
                    'quantity' => 1000,
                    'average_cost' => 2910.0,
                    'current_price' => 3000.0,
                    'unrealized_gain_amount' => 90000.0,
                    'unrealized_gain_rate' => 3.0,
                ]);
            }

            $balancingHolding = ucFrom009TestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $otherSector->id,
            ]);
            ucFrom009TestHoldingSnapshot($snapshot, $balancingHolding, [
                'quantity' => 1000,
                'average_cost' => 970.0,
                'current_price' => 1000.0,
                'unrealized_gain_amount' => 30000.0,
                'unrealized_gain_rate' => 3.0,
            ]);

            // Total portfolio value = 9,000,000 (電気機器) + 1,000,000
            // (輸送用機器) = 10,000,000 → 電気機器 allocation = 90% > 70%
            // (data-model.mdの偏り警告閾値、叩き台).
            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $allItems = collect($response->json('data.top_recommendations'))
                ->merge($response->json('data.supplementary_recommendations'));

            $rebalanceItem = $allItems->firstWhere('recommendation_type', 'リバランス');
            expect($rebalanceItem)->not->toBeNull();
            expect($rebalanceItem['target'])->toContain('電気機器');
            expect(preg_match('/\d/', (string) $rebalanceItem['reason_summary']))->toBe(1);
            // Placeholder contract — see file-level docblock. Confirm at Gate 4.
            expect($rebalanceItem['link_to'])->toBe('UC-005');
        });

        test('注目テーマに合致し財務健全性の高い未保有銘柄は新規投資候補として提案に含まれる', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();

            // A baseline currently-held position so the portfolio isn't
            // empty (UC-009's precondition: "保有銘柄全体を対象に...").
            $heldSector = ucFrom009TestSectorClassification('情報・通信業', '5250');
            $heldHolding = ucFrom009TestHolding([
                'symbol_code' => '9432', 'market' => 'jp', 'symbol_name' => 'NTT',
                'sector_classification_id' => $heldSector->id,
            ]);
            ucFrom009TestHoldingSnapshot($snapshot, $heldHolding, [
                'unrealized_gain_amount' => 500.0,
                'unrealized_gain_rate' => 5.0,
            ]);

            // Placeholder matching rule — see file-level docblock: a
            // watched_themes.name equal to the candidate's sector name.
            ucFrom009TestWatchedTheme('AI半導体');
            $themeSector = ucFrom009TestSectorClassification('AI半導体', '9999');

            $candidateHolding = ucFrom009TestHolding([
                'symbol_code' => '6920', 'market' => 'jp', 'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $themeSector->id,
            ]);
            // Deliberately no holding_snapshots row for the candidate under
            // the latest snapshot: it is a known-but-not-currently-held
            // symbol (docs/architecture/data-model.md's holdings-as-a-
            // symbol-master note), matching F-008's "新規" framing.
            ucFrom009TestFundamentalIndicator($candidateHolding, [
                'equity_ratio' => 60.0, // draft filter: equity_ratio >= 40
                'roe' => 15.0,          // draft filter: roe >= 10
            ]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $allItems = collect($response->json('data.top_recommendations'))
                ->merge($response->json('data.supplementary_recommendations'));

            $candidateItem = $allItems->firstWhere('recommendation_type', '新規投資候補');
            expect($candidateItem)->not->toBeNull();
            expect($candidateItem['target'])->toContain('6920');
            expect(preg_match('/\d/', (string) $candidateItem['reason_summary']))->toBe(1);
            // Placeholder contract — see file-level docblock. Confirm at Gate 4.
            expect($candidateItem['link_to'])->toBeIn(['UC-006', 'UC-008']);
        });
    });

    describe('異常系・境界値', function () {
        test('対象となる保有銘柄が存在しない場合は空状態のレポートになる', function () {
            [$batch] = ucFrom009TestImportBatch();
            // Deliberately no Holding/HoldingSnapshot rows created at all.

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['top_recommendations'])->toBe([]);
            expect($data['supplementary_recommendations'])->toBe([]);
            expect($data['portfolio_headline'])->toBe('現時点でおすすめできる項目はありません');
        });

        test('投資信託は取込銘柄として存在してもレコメンド対象外になる', function () {
            [$batch, $snapshot] = ucFrom009TestImportBatch();

            $mutualFund = ucFrom009TestHolding([
                'symbol_code' => '楽天・全米株式インデックス・ファンド(楽天・VTI)',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
                'symbol_name' => '楽天・全米株式インデックス・ファンド(楽天・VTI)',
                'sector_classification_id' => null,
            ]);
            // Extreme gain that would obviously qualify for 利確検討 if this
            // were an individual stock (UC-002/UC-004/UC-009業務ルール:
            // 投資信託・ETFは対象外).
            ucFrom009TestHoldingSnapshot($snapshot, $mutualFund, [
                'quantity' => 100,
                'average_cost' => 10000.0,
                'current_price' => 15000.0,
                'unrealized_gain_amount' => 500000.0,
                'unrealized_gain_rate' => 50.0,
            ]);

            $response = ucFrom009TestFetchReport($this, $batch);

            $response->assertSuccessful();

            $allTargets = collect($response->json('data.top_recommendations'))
                ->merge($response->json('data.supplementary_recommendations'))
                ->pluck('target')
                ->all();

            foreach ($allTargets as $target) {
                expect($target)->not->toContain('楽天・全米株式インデックス・ファンド');
            }
        });

        test('存在しない取込バッチIDを指定した場合は404になる', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->getJson('/import-batches/999999/summary-report');

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

            $response = $this->getJson("/import-batches/{$batch->id}/summary-report");

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001/002/003).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
