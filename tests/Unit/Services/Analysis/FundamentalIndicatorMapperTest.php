<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\FundamentalIndicatorMapper;

/*
|--------------------------------------------------------------------------
| FundamentalIndicatorMapper — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
|   - docs/architecture/data-model.md (`fundamental_indicators` table)
|   - docs/ai-context/known-pitfalls.md
|     ("J-Quants API V2 `/fins/summary` — `EqAR`/`ROE`/`PayoutRatioAnn`は
|     0〜1の比率で返る（%表記ではない）" — the ×100 conversion this class
|     is responsible for)
|
| App\Services\Analysis\FundamentalIndicatorMapper does not exist yet (no
| file at app/Services/Analysis/FundamentalIndicatorMapper.php). Every test
| below is expected to fail with a fatal "Class \"App\Services\Analysis\
| FundamentalIndicatorMapper\" not found" error at the `new
| FundamentalIndicatorMapper()` line inside the Act step — this is the
| intentional, expected Red state (same convention as
| tests/Unit/Services/Analysis/TechnicalIndicatorCalculatorTest.php).
|
| This class is pure calculation/mapping logic (no DB/HTTP dependency per
| the task description — input is the array shape already produced by
| App\Services\MarketData\JQuantsClient::fetchStatements()), so this is a
| Unit Test with no RefreshDatabase (Unit/ tests are not bound to
| Tests\TestCase in tests/Pest.php, matching TechnicalIndicatorCalculatorTest).
|
*/

/**
 * Builds a 5-period `fetchStatements()`-shaped fixture, `disclosed_date`
 * descending (index 0 = latest, index 4 = ~1 year / 4 quarterly filings
 * before index 0). All numeric fields are hand-picked to produce clean,
 * hand-verifiable expected outputs (see inline comments at each assertion
 * site) while still varying period to period.
 *
 * index 0 (latest)   : net_sales=1200, operating_profit=200, eps=120,
 *                       book_value_per_share=800, equity_to_asset_ratio=0.45,
 *                       roe=0.152, dividend_per_share_annual=36,
 *                       payout_ratio_annual=0.30
 * index 4 (4期前)     : net_sales=1000, operating_profit=160, eps=100
 *
 * @return array<int, array{
 *     disclosed_date: string,
 *     net_sales: float|null,
 *     operating_profit: float|null,
 *     profit: float|null,
 *     eps: float|null,
 *     book_value_per_share: float|null,
 *     equity_to_asset_ratio: float|null,
 *     roe: float|null,
 *     dividend_per_share_annual: float|null,
 *     payout_ratio_annual: float|null,
 * }>
 */
function fimFiveStatements(): array
{
    return [
        [
            'disclosed_date' => '2026-05-15',
            'net_sales' => 1200.0,
            'operating_profit' => 200.0,
            'profit' => 150.0,
            'eps' => 120.0,
            'book_value_per_share' => 800.0,
            'equity_to_asset_ratio' => 0.45,
            'roe' => 0.152,
            'dividend_per_share_annual' => 36.0,
            'payout_ratio_annual' => 0.30,
        ],
        [
            'disclosed_date' => '2026-02-15',
            'net_sales' => 1150.0,
            'operating_profit' => 190.0,
            'profit' => 140.0,
            'eps' => 115.0,
            'book_value_per_share' => 780.0,
            'equity_to_asset_ratio' => 0.44,
            'roe' => 0.148,
            'dividend_per_share_annual' => 35.0,
            'payout_ratio_annual' => 0.29,
        ],
        [
            'disclosed_date' => '2025-11-15',
            'net_sales' => 1100.0,
            'operating_profit' => 175.0,
            'profit' => 130.0,
            'eps' => 108.0,
            'book_value_per_share' => 760.0,
            'equity_to_asset_ratio' => 0.43,
            'roe' => 0.145,
            'dividend_per_share_annual' => 34.0,
            'payout_ratio_annual' => 0.28,
        ],
        [
            'disclosed_date' => '2025-08-15',
            'net_sales' => 1050.0,
            'operating_profit' => 165.0,
            'profit' => 120.0,
            'eps' => 102.0,
            'book_value_per_share' => 740.0,
            'equity_to_asset_ratio' => 0.42,
            'roe' => 0.140,
            'dividend_per_share_annual' => 33.0,
            'payout_ratio_annual' => 0.27,
        ],
        [
            'disclosed_date' => '2025-05-15',
            'net_sales' => 1000.0,
            'operating_profit' => 160.0,
            'profit' => 110.0,
            'eps' => 100.0,
            'book_value_per_share' => 720.0,
            'equity_to_asset_ratio' => 0.41,
            'roe' => 0.135,
            'dividend_per_share_annual' => 32.0,
            'payout_ratio_annual' => 0.26,
        ],
    ];
}

const FIM_ALL_KEYS = [
    'per', 'pbr', 'roe', 'revenue_growth', 'operating_income_growth',
    'equity_ratio', 'dividend_yield', 'dividend_payout_ratio',
    'eps_growth', 'peg_ratio',
];

// -----------------------------------------------------------------------
// 正常系: 5期分のデータ + 現在株価
// -----------------------------------------------------------------------

test('5期分の開示データと現在株価からPER・PBR・ROE等を正しく算出できる', function () {
    $result = (new FundamentalIndicatorMapper)->map(fimFiveStatements(), currentPrice: 1800.0);

    // per = currentPrice / latest eps = 1800 / 120
    expect($result['per'])->toEqualWithDelta(15.0, 0.0001);
    // pbr = currentPrice / latest book_value_per_share = 1800 / 800
    expect($result['pbr'])->toEqualWithDelta(2.25, 0.0001);
    // roe = latest roe(比率) * 100 = 0.152 * 100
    expect($result['roe'])->toEqualWithDelta(15.2, 0.0001);
    // equity_ratio = latest equity_to_asset_ratio(比率) * 100 = 0.45 * 100
    expect($result['equity_ratio'])->toEqualWithDelta(45.0, 0.0001);
    // dividend_payout_ratio = latest payout_ratio_annual(比率) * 100 = 0.30 * 100
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(30.0, 0.0001);
    // dividend_yield = latest dividend_per_share_annual / currentPrice * 100 = 36 / 1800 * 100
    expect($result['dividend_yield'])->toEqualWithDelta(2.0, 0.0001);
    // revenue_growth = (1200 - 1000) / 1000 * 100 (index0 vs index4)
    expect($result['revenue_growth'])->toEqualWithDelta(20.0, 0.0001);
    // operating_income_growth = (200 - 160) / 160 * 100 (index0 vs index4)
    expect($result['operating_income_growth'])->toEqualWithDelta(25.0, 0.0001);
    // eps_growth = (120 - 100) / 100 * 100 (index0 vs index4)
    expect($result['eps_growth'])->toEqualWithDelta(20.0, 0.0001);
    // peg_ratio = per / eps_growth = 15.0 / 20.0
    expect($result['peg_ratio'])->toEqualWithDelta(0.75, 0.0001);
});

test('ROE・自己資本比率・配当性向はJ-Quantsの比率(0〜1)からパーセント値に変換される', function () {
    // known-pitfalls.md: EqAR/ROE/PayoutRatioAnnは0〜1の比率で返るため、
    // fundamental_indicators格納時は×100してパーセント値にする必要がある。
    $result = (new FundamentalIndicatorMapper)->map(fimFiveStatements(), currentPrice: 1800.0);

    expect($result['roe'])->toEqualWithDelta(15.2, 0.0001);
    expect($result['equity_ratio'])->toEqualWithDelta(45.0, 0.0001);
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(30.0, 0.0001);
});

// -----------------------------------------------------------------------
// 境界値・データ不足
// -----------------------------------------------------------------------

test('開示データが0件の場合は全項目nullを返す（例外を投げない）', function () {
    $result = (new FundamentalIndicatorMapper)->map([], currentPrice: 1800.0);

    foreach (FIM_ALL_KEYS as $key) {
        expect($result)->toHaveKey($key);
        expect($result[$key])->toBeNull();
    }
});

test('開示データが5件未満（3件）の場合、成長率系はnullだが最新1期のみで算出できる項目は算出される', function () {
    $statements = array_slice(fimFiveStatements(), 0, 3);

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    // 4期前 ($statements[4]) が存在しないため成長率系は算出不可
    expect($result['revenue_growth'])->toBeNull();
    expect($result['operating_income_growth'])->toBeNull();
    expect($result['eps_growth'])->toBeNull();
    expect($result['peg_ratio'])->toBeNull();

    // 最新1期(index0)のみで算出できる項目は3件でも正常に算出される
    expect($result['per'])->toEqualWithDelta(15.0, 0.0001);
    expect($result['pbr'])->toEqualWithDelta(2.25, 0.0001);
    expect($result['roe'])->toEqualWithDelta(15.2, 0.0001);
    expect($result['equity_ratio'])->toEqualWithDelta(45.0, 0.0001);
    expect($result['dividend_yield'])->toEqualWithDelta(2.0, 0.0001);
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(30.0, 0.0001);
});

test('currentPriceがnullの場合、PER・PBR・配当利回りはnullだがそれ以外はcurrentPrice非依存のため算出される', function () {
    $result = (new FundamentalIndicatorMapper)->map(fimFiveStatements(), currentPrice: null);

    expect($result['per'])->toBeNull();
    expect($result['pbr'])->toBeNull();
    expect($result['dividend_yield'])->toBeNull();

    // per が null のため peg_ratio も算出不可（per非null条件を満たさない）
    expect($result['peg_ratio'])->toBeNull();

    expect($result['roe'])->toEqualWithDelta(15.2, 0.0001);
    expect($result['equity_ratio'])->toEqualWithDelta(45.0, 0.0001);
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(30.0, 0.0001);
    expect($result['revenue_growth'])->toEqualWithDelta(20.0, 0.0001);
    expect($result['operating_income_growth'])->toEqualWithDelta(25.0, 0.0001);
    expect($result['eps_growth'])->toEqualWithDelta(20.0, 0.0001);
});

test('EPS成長率が0以下（減益・横ばい）の場合、PERが算出できてもPEGレシオはnullになる', function () {
    $statements = fimFiveStatements();
    // 最新期のEPSを4期前(100)より低い90に変更 -> eps_growth = (90-100)/100*100 = -10.0
    $statements[0]['eps'] = 90.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    // per = 1800 / 90 は正常に算出される
    expect($result['per'])->toEqualWithDelta(20.0, 0.0001);
    expect($result['eps_growth'])->toEqualWithDelta(-10.0, 0.0001);
    expect($result['peg_ratio'])->toBeNull();
});

test('最新期のroeがJ-Quants非開示(null)でも、roe以外の項目は影響を受けない', function () {
    $statements = fimFiveStatements();
    $statements[0]['roe'] = null;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['roe'])->toBeNull();

    expect($result['per'])->toEqualWithDelta(15.0, 0.0001);
    expect($result['pbr'])->toEqualWithDelta(2.25, 0.0001);
    expect($result['equity_ratio'])->toEqualWithDelta(45.0, 0.0001);
    expect($result['dividend_yield'])->toEqualWithDelta(2.0, 0.0001);
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(30.0, 0.0001);
    expect($result['revenue_growth'])->toEqualWithDelta(20.0, 0.0001);
});

test('最新期のepsがJ-Quants非開示(null)の場合、PER・EPS成長率・PEGレシオがnullになるが他の項目は影響を受けない', function () {
    $statements = fimFiveStatements();
    $statements[0]['eps'] = null;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['per'])->toBeNull();
    expect($result['eps_growth'])->toBeNull();
    expect($result['peg_ratio'])->toBeNull();

    expect($result['pbr'])->toEqualWithDelta(2.25, 0.0001);
    expect($result['roe'])->toEqualWithDelta(15.2, 0.0001);
    expect($result['equity_ratio'])->toEqualWithDelta(45.0, 0.0001);
    expect($result['dividend_yield'])->toEqualWithDelta(2.0, 0.0001);
    expect($result['dividend_payout_ratio'])->toEqualWithDelta(30.0, 0.0001);
    // revenue_growth/operating_income_growthはepsに依存しないため影響を受けない
    expect($result['revenue_growth'])->toEqualWithDelta(20.0, 0.0001);
    expect($result['operating_income_growth'])->toEqualWithDelta(25.0, 0.0001);
});

test('最新期のEPSが0以下（赤字転落等）の場合、PERは算出せずnullにする', function () {
    $statements = fimFiveStatements();
    $statements[0]['eps'] = -5.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['per'])->toBeNull();
});

test('最新期の1株純資産が0以下の場合、PBRは算出せずnullにする', function () {
    $statements = fimFiveStatements();
    $statements[0]['book_value_per_share'] = 0.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['pbr'])->toBeNull();
});
