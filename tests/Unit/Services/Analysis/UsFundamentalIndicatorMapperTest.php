<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\UsFundamentalIndicatorMapper;

/*
|--------------------------------------------------------------------------
| UsFundamentalIndicatorMapper — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md (D2〜D3: a new,
|     separate mapper for Finnhub input — NOT a branch inside the existing
|     J-Quants-oriented FundamentalIndicatorMapper — plus the exact field
|     mapping rules and the "実測計算・近似不採用" equity_ratio rationale)
|   - docs/architecture/data-model.md (`fundamental_indicators` — the same
|     market-agnostic output shape this mapper must produce, matching
|     App\Services\Analysis\FundamentalIndicatorMapper::map()'s return
|     array shape exactly)
|   - Task description for this Red-phase generation (per-field calculation
|     rules, and the real, HTTP-verified AAPL fixture values used below)
|
| App\Services\Analysis\UsFundamentalIndicatorMapper does not exist yet (no
| file at app/Services/Analysis/UsFundamentalIndicatorMapper.php — verified
| via `find` before writing this file). Every test below is expected to fail
| with a fatal "Class \"App\Services\Analysis\UsFundamentalIndicatorMapper\"
| not found" error at the `new UsFundamentalIndicatorMapper()` line inside
| the Act step — this is the intentional, expected Red state (same
| convention as tests/Unit/Services/Analysis/FundamentalIndicatorMapperTest.php
| for its J-Quants-oriented sibling).
|
| This class is pure calculation/mapping logic (no DB/HTTP dependency, per
| the task description), so this is a Unit Test with no RefreshDatabase
| (Unit/ tests are not bound to Tests\TestCase in tests/Pest.php, matching
| FundamentalIndicatorMapperTest).
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - `map(array $metrics, array $reportedFinancials): array` — the caller
|     (FetchExternalMarketDataAction) is expected to convert a null
|     FinnhubClientInterface::fetchMetrics() return value into an empty
|     array before calling this method (per the task description's docblock
|     note on the `$metrics` param). This test file exercises `map([], ...)`
|     directly to pin that "empty metrics -> all metric-derived fields
|     null" contract, rather than assuming the mapper itself accepts
|     `?array` for $metrics.
|   - `equity_ratio`'s divide-by-zero guard treats `total_assets === 0.0` as
|     the null-triggering condition specified by the task description
|     ("total_assetsが0の場合はnull"), independent of whether it's `0` (int)
|     or `0.0` (float) — this test uses `0.0` to match the float-typed
|     shape FinnhubClientInterface::fetchReportedFinancials() documents.
|
*/

/**
 * Real, HTTP-verified Finnhub `stock/metric` response fields for AAPL, as
 * given in the task description (`peTTM`/`pbAnnual`/`roeTTM`/
 * `revenueGrowthTTMYoy`/`epsGrowthTTMYoy`/`dividendYieldIndicatedAnnual`/
 * `payoutRatioTTM`/`pegTTM`).
 *
 * @return array<string, mixed>
 */
function ufimAaplMetrics(): array
{
    return [
        'peTTM' => 37.3169,
        'pbAnnual' => 50.978,
        'roeTTM' => 137.18,
        'revenueGrowthTTMYoy' => 14.24,
        'epsGrowthTTMYoy' => 32.61,
        'dividendYieldIndicatedAnnual' => 0.50534,
        'payoutRatioTTM' => 12.13,
        'pegTTM' => 2.93443,
    ];
}

/**
 * Real, HTTP-verified `financials-reported` FY2025 total_assets/total_equity
 * for AAPL (task description: 359241000000 / 73733000000, internally
 * consistent with total_liabilities=285508000000). `operating_income` for
 * both periods is a fixture value invented for this test (not given by the
 * task description, which only pinned down the equity_ratio inputs) — kept
 * as a clean round number so the YoY growth rate is hand-verifiable.
 *
 * @return array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>
 */
function ufimAaplReportedFinancials(): array
{
    return [
        ['operating_income' => 100000000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
        ['operating_income' => 80000000000.0, 'total_assets' => 352755000000.0, 'total_equity' => 56950000000.0],
    ];
}

const UFIM_ALL_KEYS = [
    'per', 'pbr', 'roe', 'revenue_growth', 'operating_income_growth',
    'equity_ratio', 'dividend_yield', 'dividend_payout_ratio',
    'eps_growth', 'peg_ratio',
];

// -----------------------------------------------------------------------
// 正常系: 実データ相当のmetrics/reportedFinancialsから全項目を算出
// -----------------------------------------------------------------------

test('Finnhubのmetricsフィールドはそのまま採用され、equity_ratio/operating_income_growthはreportedFinancialsから実測算出される', function () {
    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), ufimAaplReportedFinancials());

    // metricsの各フィールドは変換せずそのまま採用する（ADR-0009 D3）
    expect($result['per'])->toEqualWithDelta(37.3169, 0.0001);
    expect($result['pbr'])->toEqualWithDelta(50.978, 0.0001);
    expect($result['roe'])->toEqualWithDelta(137.18, 0.0001);
    expect($result['revenue_growth'])->toEqualWithDelta(14.24, 0.0001);
    expect($result['eps_growth'])->toEqualWithDelta(32.61, 0.0001);
    expect($result['dividend_yield'])->toEqualWithDelta(0.50534, 0.00001);
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(12.13, 0.0001);
    expect($result['peg_ratio'])->toEqualWithDelta(2.93443, 0.00001);

    // equity_ratio = total_equity / total_assets * 100（実測、近似不採用）
    expect($result['equity_ratio'])->toEqualWithDelta(73733000000 / 359241000000 * 100, 0.0001);

    // operating_income_growth = (当期 - 前期) / 前期 * 100 = (100,000,000,000 - 80,000,000,000) / 80,000,000,000 * 100 = 25%
    expect($result['operating_income_growth'])->toEqualWithDelta(25.0, 0.0001);
});

test('全キーが返却される（returnの形状がJP用FundamentalIndicatorMapper::map()の戻り値と完全一致する）', function () {
    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), ufimAaplReportedFinancials());

    foreach (UFIM_ALL_KEYS as $key) {
        expect($result)->toHaveKey($key);
    }
    expect($result)->toHaveCount(count(UFIM_ALL_KEYS));
});

// -----------------------------------------------------------------------
// metricsが欠損している場合
// -----------------------------------------------------------------------

test('metricsが空配列の場合、metrics由来の全フィールド（per/pbr/roe/revenue_growth/eps_growth/dividend_yield/dividend_payout_ratio/peg_ratio）はnullになる', function () {
    $result = (new UsFundamentalIndicatorMapper)->map([], ufimAaplReportedFinancials());

    expect($result['per'])->toBeNull();
    expect($result['pbr'])->toBeNull();
    expect($result['roe'])->toBeNull();
    expect($result['revenue_growth'])->toBeNull();
    expect($result['eps_growth'])->toBeNull();
    expect($result['dividend_yield'])->toBeNull();
    expect($result['dividend_payout_ratio'])->toBeNull();
    expect($result['peg_ratio'])->toBeNull();

    // reportedFinancials由来の項目はmetricsの欠損による影響を受けない
    expect($result['equity_ratio'])->toEqualWithDelta(73733000000 / 359241000000 * 100, 0.0001);
    expect($result['operating_income_growth'])->toEqualWithDelta(25.0, 0.0001);
});

test('metricsに個別のキーが存在しない場合、そのフィールドのみnullになり他のフィールドは影響を受けない', function () {
    $metrics = ufimAaplMetrics();
    unset($metrics['peTTM'], $metrics['pegTTM']);

    $result = (new UsFundamentalIndicatorMapper)->map($metrics, ufimAaplReportedFinancials());

    expect($result['per'])->toBeNull();
    expect($result['peg_ratio'])->toBeNull();

    expect($result['pbr'])->toEqualWithDelta(50.978, 0.0001);
    expect($result['roe'])->toEqualWithDelta(137.18, 0.0001);
    expect($result['revenue_growth'])->toEqualWithDelta(14.24, 0.0001);
    expect($result['eps_growth'])->toEqualWithDelta(32.61, 0.0001);
});

// -----------------------------------------------------------------------
// equity_ratio: 境界値・データ欠損
// -----------------------------------------------------------------------

test('reportedFinancialsが空配列の場合（当期データなし）、equity_ratio・operating_income_growthは共にnullになる', function () {
    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), []);

    expect($result['equity_ratio'])->toBeNull();
    expect($result['operating_income_growth'])->toBeNull();

    // metrics由来の項目はreportedFinancialsの欠損による影響を受けない
    expect($result['per'])->toEqualWithDelta(37.3169, 0.0001);
});

test('当期(reportedFinancials[0])のtotal_assetsが0の場合、equity_ratioはnullになる（ゼロ除算防止）', function () {
    $reportedFinancials = ufimAaplReportedFinancials();
    $reportedFinancials[0]['total_assets'] = 0.0;

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['equity_ratio'])->toBeNull();
});

test('当期(reportedFinancials[0])のtotal_equityがnullの場合、equity_ratioはnullになる', function () {
    $reportedFinancials = ufimAaplReportedFinancials();
    $reportedFinancials[0]['total_equity'] = null;

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['equity_ratio'])->toBeNull();
});

test('当期(reportedFinancials[0])のtotal_assetsがnullの場合、equity_ratioはnullになる', function () {
    $reportedFinancials = ufimAaplReportedFinancials();
    $reportedFinancials[0]['total_assets'] = null;

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['equity_ratio'])->toBeNull();
});

// -----------------------------------------------------------------------
// operating_income_growth: 境界値・データ欠損
// -----------------------------------------------------------------------

test('前期(reportedFinancials[1])が存在しない場合、operating_income_growthはnullになる（比較対象なし）', function () {
    $reportedFinancials = [ufimAaplReportedFinancials()[0]];

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['operating_income_growth'])->toBeNull();
    // equity_ratioはreportedFinancials[0]のみで算出可能なため影響を受けない
    expect($result['equity_ratio'])->toEqualWithDelta(73733000000 / 359241000000 * 100, 0.0001);
});

test('前期(reportedFinancials[1])のoperating_incomeが0の場合、operating_income_growthはnullになる（ゼロ除算防止）', function () {
    $reportedFinancials = ufimAaplReportedFinancials();
    $reportedFinancials[1]['operating_income'] = 0.0;

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['operating_income_growth'])->toBeNull();
});

test('当期(reportedFinancials[0])のoperating_incomeがnullの場合、operating_income_growthはnullになる', function () {
    $reportedFinancials = ufimAaplReportedFinancials();
    $reportedFinancials[0]['operating_income'] = null;

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['operating_income_growth'])->toBeNull();
});

test('前期(reportedFinancials[1])のoperating_incomeがnullの場合、operating_income_growthはnullになる', function () {
    $reportedFinancials = ufimAaplReportedFinancials();
    $reportedFinancials[1]['operating_income'] = null;

    $result = (new UsFundamentalIndicatorMapper)->map(ufimAaplMetrics(), $reportedFinancials);

    expect($result['operating_income_growth'])->toBeNull();
});
