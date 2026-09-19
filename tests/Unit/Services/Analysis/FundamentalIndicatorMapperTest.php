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
| -------------------------------------------------------------------------
| CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率（operating_margin）を
| map() の返却配列に追加
| -------------------------------------------------------------------------
| JP（J-Quants）は最新期（index 0）から実測算出する（ADR-0011 D4）:
|   operating_margin = operating_profit / net_sales * 100
|   - net_sales が null / 0以下、operating_profit が null → null
|     （calculatePer() / calculatePbr() と同じガードパターン）
|   - |operating_margin| > 999 → null（ADR-0011 D5。売上ほぼゼロ企業の
|     ACHR -243100% のような異常値を DB に入れない）
|
| 現行 map() は返却配列に `operating_margin` キーを持たないため、
| `toHaveKey('operating_margin')` / FIM_ALL_KEYS を使った全キー検証は
| アサーション不一致で Red（fatal ではない）。
|
*/

/**
 * Builds a 5-period `fetchStatements()`-shaped fixture, `disclosed_date`
 * descending (index 0 = latest). All numeric fields are hand-picked to
 * produce clean, hand-verifiable expected outputs (see inline comments at
 * each assertion site) while still varying period to period.
 *
 * -------------------------------------------------------------------------
 * ADR-0012 (2026-09-06): 成長率は「最新の本決算(FY)」と「前期の本決算(FY)」の
 * 比較で算出する（従来の「配列 index 0 と index 4 の比較」を廃止）。
 * `fetchStatements()` の各行に `period_type`(1Q/2Q/3Q/FY) と
 * `fiscal_year_end`(会計年度末) が付く。
 *
 * この基本フィクスチャは:
 *   index 0 : FY   fiscal_year_end=2026-03-31 （最新の本決算）
 *   index 1 : 3Q   fiscal_year_end=2026-03-31
 *   index 2 : 2Q   fiscal_year_end=2026-03-31
 *   index 3 : 1Q   fiscal_year_end=2026-03-31
 *   index 4 : FY   fiscal_year_end=2025-03-31 （前期の本決算）
 * とし、成長率は index 0(FY) vs index 4(FY) になる（値は従来テストと同じ）。
 * -------------------------------------------------------------------------
 *
 * @return array<int, array{
 *     disclosed_date: string,
 *     period_type: string,
 *     fiscal_year_end: string,
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
            'period_type' => 'FY',
            'fiscal_year_end' => '2026-03-31',
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
            'period_type' => '3Q',
            'fiscal_year_end' => '2026-03-31',
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
            'period_type' => '2Q',
            'fiscal_year_end' => '2026-03-31',
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
            'period_type' => '1Q',
            'fiscal_year_end' => '2026-03-31',
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
            'period_type' => 'FY',
            'fiscal_year_end' => '2025-03-31',
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
    'equity_ratio', 'operating_margin', 'dividend_yield', 'dividend_payout_ratio',
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
    // operating_margin = latest operating_profit / net_sales * 100 = 200 / 1200 * 100 (CHG-0012 / ADR-0011)
    expect($result['operating_margin'])->toEqualWithDelta(200 / 1200 * 100, 0.0001);
});

// -----------------------------------------------------------------------
// CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率の算出・ガード
// -----------------------------------------------------------------------

test('営業利益率は最新期(index0)の operating_profit / net_sales * 100 で算出される', function () {
    $statements = fimFiveStatements();
    $statements[0]['net_sales'] = 1000.0;
    $statements[0]['operating_profit'] = 150.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    // 150 / 1000 * 100 = 15.0
    expect($result['operating_margin'])->toEqualWithDelta(15.0, 0.0001);
});

test('最新期の operating_profit が null の場合、営業利益率は null になる', function () {
    $statements = fimFiveStatements();
    $statements[0]['operating_profit'] = null;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['operating_margin'])->toBeNull();
});

test('最新期の net_sales が 0 の場合、営業利益率は null になる（ゼロ除算防止）', function () {
    $statements = fimFiveStatements();
    $statements[0]['net_sales'] = 0.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['operating_margin'])->toBeNull();
});

test('最新期の net_sales がマイナス（債務超過的な特殊開示）の場合、営業利益率は null になる', function () {
    $statements = fimFiveStatements();
    $statements[0]['net_sales'] = -500.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['operating_margin'])->toBeNull();
});

test('最新期の net_sales が null の場合、営業利益率は null になる', function () {
    $statements = fimFiveStatements();
    $statements[0]['net_sales'] = null;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['operating_margin'])->toBeNull();
});

test('売上がほぼゼロで営業損失が巨大なプレレベニュー企業（|営業利益率| > 999%）の場合、営業利益率は null になる（ADR-0011 D5）', function () {
    $statements = fimFiveStatements();
    // net_sales=1, operating_profit=-3000 → -300000% → |値| > 999 → null
    $statements[0]['net_sales'] = 1.0;
    $statements[0]['operating_profit'] = -3000.0;

    $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 1800.0);

    expect($result['operating_margin'])->toBeNull();
});

test('開示データが0件の場合、営業利益率も null になる（FIM_ALL_KEYS の一括検証に含まれる）', function () {
    $result = (new FundamentalIndicatorMapper)->map([], currentPrice: 1800.0);

    expect($result)->toHaveKey('operating_margin');
    expect($result['operating_margin'])->toBeNull();
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

    // 前期の本決算(FY)が window 内に無いため成長率系は算出不可（ADR-0012）
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

// -----------------------------------------------------------------------
// CR (2026-09-06, ADR-0012): 成長率を「最新の本決算(FY) vs 前期の本決算(FY)」
// で算出する（配列位置ベースの index0 vs index4 を廃止）
// -----------------------------------------------------------------------
// Source of truth:
//   - docs/adr/ADR-0012-growth-rate-fy-comparison.md
//   - docs/ai-context/known-pitfalls.md
//     ("J-Quants API V2 `/fins/summary` — 同一決算の重複開示・累計期
//     （1Q/2Q/3Q/FY）混在で「配列N件前」の位置ベース比較が壊れる")
//
// Expected Red: 現行 calculateGrowth() は period_type / fiscal_year_end を
// 見ず $statements[0] と $statements[4] を比較するため、下記が assertion
// 不一致で落ちる（annualGrowth() の存在チェックは fatal ではなく false）。

describe('ADR-0012: 成長率は本決算(FY)どうしの前期比で算出する', function () {
    /**
     * `disclosed_date` 降順。FY を2期、その間に四半期行と重複開示をはさむ。
     * 最新FY(2026-03-31): net_sales=1400, operating_profit=224, eps=140
     * 前期FY(2025-03-31): net_sales=1000, operating_profit=160, eps=100
     * 期待成長率: 売上 +40%, 営業利益 +40%, EPS +40%
     *
     * @return array<int, array<string, mixed>>
     */
    function fimMixedPeriodStatements(): array
    {
        $base = [
            'profit' => 100.0, 'book_value_per_share' => 800.0,
            'equity_to_asset_ratio' => 0.45, 'roe' => 0.15,
            'dividend_per_share_annual' => 30.0, 'payout_ratio_annual' => 0.3,
        ];

        return [
            array_merge($base, ['disclosed_date' => '2026-05-10', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 1400.0, 'operating_profit' => 224.0, 'eps' => 140.0]),
            array_merge($base, ['disclosed_date' => '2026-02-12', 'period_type' => '3Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 1050.0, 'operating_profit' => 170.0, 'eps' => 105.0]),
            array_merge($base, ['disclosed_date' => '2026-02-05', 'period_type' => '3Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 1050.0, 'operating_profit' => 170.0, 'eps' => 105.0]),
            array_merge($base, ['disclosed_date' => '2025-11-05', 'period_type' => '2Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 700.0, 'operating_profit' => 112.0, 'eps' => 70.0]),
            array_merge($base, ['disclosed_date' => '2025-08-06', 'period_type' => '1Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 350.0, 'operating_profit' => 56.0, 'eps' => 35.0]),
            array_merge($base, ['disclosed_date' => '2025-05-12', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31', 'net_sales' => 1000.0, 'operating_profit' => 160.0, 'eps' => 100.0]),
        ];
    }

    test('四半期行や重複開示をはさんでも、最新の本決算と前期の本決算を比較して成長率を算出する', function () {
        $result = (new FundamentalIndicatorMapper)->map(fimMixedPeriodStatements(), currentPrice: 2800.0);

        // 位置ベース($statements[4] = 1Q, eps=35)なら (140-35)/35*100 = 300% になってしまう
        expect($result['revenue_growth'])->toEqualWithDelta(40.0, 0.0001);
        expect($result['operating_income_growth'])->toEqualWithDelta(40.0, 0.0001);
        expect($result['eps_growth'])->toEqualWithDelta(40.0, 0.0001);
        // peg = per / eps_growth = (2800/140) / 40 = 20 / 40 = 0.5
        expect($result['peg_ratio'])->toEqualWithDelta(0.5, 0.0001);
    });

    test('同一会計年度の本決算が重複開示されても、片方を「前期」と誤認しない', function () {
        $statements = fimMixedPeriodStatements();
        array_splice($statements, 1, 0, [[
            'disclosed_date' => '2026-05-08', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31',
            'net_sales' => 1400.0, 'operating_profit' => 224.0, 'eps' => 140.0, 'profit' => 100.0,
            'book_value_per_share' => 800.0, 'equity_to_asset_ratio' => 0.45, 'roe' => 0.15,
            'dividend_per_share_annual' => 30.0, 'payout_ratio_annual' => 0.3,
        ]]);

        $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 2800.0);

        // 重複FYを「前期」と扱うと成長率0%になる。正しくは前期FY(2025-03-31)と比較して+40%
        expect($result['eps_growth'])->toEqualWithDelta(40.0, 0.0001);
    });

    test('本決算(FY)が1期ぶんしか無い場合、成長率系は算出できずnullになる', function () {
        $statements = array_values(array_filter(
            fimMixedPeriodStatements(),
            fn (array $s) => $s['fiscal_year_end'] !== '2025-03-31',
        ));

        $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 2800.0);

        expect($result['revenue_growth'])->toBeNull();
        expect($result['operating_income_growth'])->toBeNull();
        expect($result['eps_growth'])->toBeNull();
        expect($result['peg_ratio'])->toBeNull();
    });

    test('伊藤忠(8001)相当: 最新FYのEPSが前期FYより大幅減 → eps_growthは負・PEGレシオはnull', function () {
        $base = [
            'profit' => 100.0, 'book_value_per_share' => 942.78,
            'equity_to_asset_ratio' => 0.394, 'roe' => 0.146,
            'dividend_per_share_annual' => null, 'payout_ratio_annual' => 0.328,
        ];
        $statements = [
            array_merge($base, ['disclosed_date' => '2026-05-01', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 14823087.0, 'operating_profit' => 701888.0, 'eps' => 128.0]),
            array_merge($base, ['disclosed_date' => '2026-02-13', 'period_type' => '3Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 10986251.0, 'operating_profit' => 526438.0, 'eps' => 100.11]),
            array_merge($base, ['disclosed_date' => '2026-02-06', 'period_type' => '3Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 10986251.0, 'operating_profit' => 526438.0, 'eps' => 100.11]),
            array_merge($base, ['disclosed_date' => '2025-11-05', 'period_type' => '2Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 7249159.0, 'operating_profit' => 354140.0, 'eps' => 354.18]),
            array_merge($base, ['disclosed_date' => '2025-08-06', 'period_type' => '1Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 3558933.0, 'operating_profit' => 170735.0, 'eps' => 200.5]),
            array_merge($base, ['disclosed_date' => '2025-05-02', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31', 'net_sales' => 14724234.0, 'operating_profit' => 700000.0, 'eps' => 615.65]),
        ];

        $result = (new FundamentalIndicatorMapper)->map($statements, currentPrice: 2204.0);

        expect($result['eps_growth'])->toEqualWithDelta((128.0 - 615.65) / 615.65 * 100, 0.01);
        expect($result['peg_ratio'])->toBeNull();
        // 位置ベースのバグ値(通期売上 ÷ 1Q売上 ≒ +316%)ではないこと
        expect($result['revenue_growth'])->toEqualWithDelta((14823087.0 - 14724234.0) / 14724234.0 * 100, 0.01);
        expect($result['revenue_growth'])->toBeLessThan(10.0);
    });

    test('annualGrowth()はpublicメソッドとして最新FYと前期FYの成長率を返す（FetchExternalMarketDataActionと共有）', function () {
        $mapper = new FundamentalIndicatorMapper;

        expect(method_exists($mapper, 'annualGrowth'))->toBeTrue();
        expect($mapper->annualGrowth(fimFiveStatements(), 'net_sales'))->toEqualWithDelta(20.0, 0.0001);
        expect($mapper->annualGrowth(fimFiveStatements(), 'eps'))->toEqualWithDelta(20.0, 0.0001);
    });
});

/*
|--------------------------------------------------------------------------
| ADR-0015 D2: averageAnnualGrowth() — 直近3〜5期平均成長率のOR救済経路
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md (D2)
|   - docs/architecture/data-model.md
|     ("成長率OR救済の算出期数" / financial_statements.period_type /
|     financial_statements.fiscal_year_end)
|
| App\Services\Analysis\FundamentalIndicatorMapper::averageAnnualGrowth()
| does not exist yet. Every test below is expected to fail with a fatal
| "Call to undefined method
| App\Services\Analysis\FundamentalIndicatorMapper::averageAnnualGrowth()"
| error — the intentional, expected Red state (same convention as the
| operating_margin block above before CHG-0012's Green phase).
|
| Specified contract (task description + chosen fail-safe behavior for
| missing values, documented inline at each relevant test):
|   - Same FY-only filter + fiscal_year_end dedup + descending sort as
|     annualGrowth() (duplicated logic is acceptable for this Cycle; a
|     Refactor-phase extraction is out of scope here).
|   - Computes the most recent `$periods` YoY growth rates (each comparing
|     two consecutive FY rows, same formula as annualGrowth()) and returns
|     their simple average.
|   - Requires at least `$periods + 1` FY rows (to form `$periods`
|     consecutive comparisons); otherwise returns null.
|   - Chosen missing-value behavior (see below): if ANY of the `$periods`
|     comparisons is unavailable (either side null, or the past side 0),
|     the whole method returns null rather than averaging over the
|     remaining computable periods. This matches annualGrowth()'s existing
|     "fail-safe → null" pattern (annualGrowth() never partially computes;
|     it's all-or-nothing), so averageAnnualGrowth() is kept consistent
|     with it instead of introducing a different partial-average behavior.
|   - `$periods` defaults to 3 (data-model.md: "3期・4期・5期のどれを採用す
|     るかは実装時に実データで比較して確定" — 3 is the provisional default).
|
*/

describe('ADR-0015 D2: averageAnnualGrowth()は直近$periods期のYoY成長率の単純平均を返す', function () {
    /**
     * 5期分のFY（本決算）行。disclosed_date降順・fiscal_year_end降順。
     * net_salesは意図的に「なめらかでない」値にして、平均計算の取り違えを
     * 検知しやすくしている。
     *
     * FY2026: net_sales=1200 <- FY2025比 (1200-1000)/1000*100 = +20%
     * FY2025: net_sales=1000 <- FY2024比 (1000-800)/800*100  = +25%
     * FY2024: net_sales=800  <- FY2023比 (800-500)/500*100   = +60%
     * FY2023: net_sales=500  <- FY2022比 (500-400)/400*100   = +25%
     * FY2022: net_sales=400
     *
     * 直近3期平均（periods=3、FY2026/2025/2024の3成長率）:
     *   (20 + 25 + 60) / 3 = 35.0
     *
     * @return array<int, array{disclosed_date: string, period_type: string, fiscal_year_end: string, net_sales: float}>
     */
    function famgFiveFyStatements(): array
    {
        return [
            ['disclosed_date' => '2026-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 1200.0],
            ['disclosed_date' => '2025-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31', 'net_sales' => 1000.0],
            ['disclosed_date' => '2024-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2024-03-31', 'net_sales' => 800.0],
            ['disclosed_date' => '2023-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2023-03-31', 'net_sales' => 500.0],
            ['disclosed_date' => '2022-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2022-03-31', 'net_sales' => 400.0],
        ];
    }

    /**
     * famgFiveFyStatements()に、FY2021(net_sales=300)を加えた6期分。
     * FY2022比 (400-300)/300*100 = +33.3333...%
     * periods=5で使う直近5成長率: 20, 25, 60, 25, 33.3333... の平均に使用。
     *
     * @return array<int, array{disclosed_date: string, period_type: string, fiscal_year_end: string, net_sales: float}>
     */
    function famgSixFyStatements(): array
    {
        $statements = famgFiveFyStatements();
        $statements[] = ['disclosed_date' => '2021-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2021-03-31', 'net_sales' => 300.0];

        return $statements;
    }

    test('FYが十分な期数（5期）ある場合、デフォルト(periods=3)で直近3期分のYoY成長率の単純平均が返る', function () {
        $mapper = new FundamentalIndicatorMapper;

        $result = $mapper->averageAnnualGrowth(famgFiveFyStatements(), 'net_sales');

        // (20 + 25 + 60) / 3 = 35.0
        expect($result)->toEqualWithDelta(35.0, 0.0001);
    });

    test('FY期数がちょうどperiods+1（4期）ぴったりの場合、境界値として正しく算出される', function () {
        $mapper = new FundamentalIndicatorMapper;
        $statements = array_slice(famgFiveFyStatements(), 0, 4); // FY2026〜FY2023の4期

        $result = $mapper->averageAnnualGrowth($statements, 'net_sales', periods: 3);

        // (20 + 25 + 60) / 3 = 35.0 (FY2023はFY2022無しに比較不能なため使わない)
        expect($result)->toEqualWithDelta(35.0, 0.0001);
    });

    test('FY期数がperiods+1未満（3期しかなく2成長率しか作れない）の場合、nullを返す', function () {
        $mapper = new FundamentalIndicatorMapper;
        $statements = array_slice(famgFiveFyStatements(), 0, 3); // FY2026〜FY2024の3期（periods=3にはFY4期必要）

        $result = $mapper->averageAnnualGrowth($statements, 'net_sales', periods: 3);

        expect($result)->toBeNull();
    });

    test('FYでない行（1Q/2Q/3Q）が混在していても、FY行のみを対象に正しく算出される', function () {
        $mapper = new FundamentalIndicatorMapper;
        $statements = famgFiveFyStatements();

        // 各FYの間に、FYとは全く異なる値を持つ四半期行を挟み込む。
        // これらがフィルタされず計算に混入すると 35.0 からずれるはず。
        array_splice($statements, 1, 0, [
            ['disclosed_date' => '2026-02-15', 'period_type' => '3Q', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 99999.0],
        ]);
        array_splice($statements, 3, 0, [
            ['disclosed_date' => '2025-02-15', 'period_type' => '2Q', 'fiscal_year_end' => '2025-03-31', 'net_sales' => 1.0],
        ]);

        $result = $mapper->averageAnnualGrowth($statements, 'net_sales');

        expect($result)->toEqualWithDelta(35.0, 0.0001);
    });

    test('同一fiscal_year_endの重複開示行がある場合、重複排除されて正しく算出される', function () {
        $mapper = new FundamentalIndicatorMapper;
        $statements = famgFiveFyStatements();

        // FY2026の重複開示（同一fiscal_year_end、net_salesが異なる異常値）を
        // 直後に挿入。annualGrowth()と同じdedupロジックなら、配列で先に現れる
        // （＝最新開示）方が採用され、重複行は無視されるはず。
        array_splice($statements, 1, 0, [
            ['disclosed_date' => '2026-05-08', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31', 'net_sales' => 77777.0],
        ]);

        $result = $mapper->averageAnnualGrowth($statements, 'net_sales');

        expect($result)->toEqualWithDelta(35.0, 0.0001);
    });

    test('比較対象期のいずれかの値がnullを含む場合、全体がnullになる（fail-safe、annualGrowth()と同じ挙動に統一）', function () {
        $mapper = new FundamentalIndicatorMapper;
        $statements = famgFiveFyStatements();
        // FY2024のnet_salesが非開示(null)になったケース。
        // periods=3の3成長率のうち、FY2025→FY2024間の比較が算出不可になる。
        // 選択した仕様: 算出可能な残り2成長率だけで平均せず、全体をnullにする。
        $statements[2]['net_sales'] = null;

        $result = $mapper->averageAnnualGrowth($statements, 'net_sales', periods: 3);

        expect($result)->toBeNull();
    });

    test('比較対象期のいずれかの値が0を含む場合も、全体がnullになる（ゼロ除算防止、fail-safe）', function () {
        $mapper = new FundamentalIndicatorMapper;
        $statements = famgFiveFyStatements();
        // FY2022(直近3成長率には含まれない最古期の1つ前)のnet_salesを0にする。
        // これはFY2023→FY2022間の比較にのみ影響するが、periods=3では
        // FY2026/2025/2024の3成長率を使うため、この0はそもそも計算対象外
        // ——という前提を崩さないよう、periods=4に指定してFY2023→FY2022比較を
        // 計算範囲に含める。
        $statements[4]['net_sales'] = 0.0;

        $result = $mapper->averageAnnualGrowth($statements, 'net_sales', periods: 4);

        expect($result)->toBeNull();
    });

    test('periods=4を明示的に指定した場合、直近4期分のYoY成長率の平均が返る', function () {
        $mapper = new FundamentalIndicatorMapper;

        $result = $mapper->averageAnnualGrowth(famgFiveFyStatements(), 'net_sales', periods: 4);

        // (20 + 25 + 60 + 25) / 4 = 32.5
        expect($result)->toEqualWithDelta(32.5, 0.0001);
    });

    test('periods=5を明示的に指定した場合、直近5期分のYoY成長率の平均が返る', function () {
        $mapper = new FundamentalIndicatorMapper;

        $result = $mapper->averageAnnualGrowth(famgSixFyStatements(), 'net_sales', periods: 5);

        // (20 + 25 + 60 + 25 + 33.3333...) / 5 = 32.66666...
        $expected = (20.0 + 25.0 + 60.0 + 25.0 + ((400.0 - 300.0) / 300.0 * 100)) / 5;
        expect($result)->toEqualWithDelta($expected, 0.0001);
    });

    test('periods=5指定でFYが5期しかない場合（periods+1に満たない）、nullを返す', function () {
        $mapper = new FundamentalIndicatorMapper;

        $result = $mapper->averageAnnualGrowth(famgFiveFyStatements(), 'net_sales', periods: 5);

        expect($result)->toBeNull();
    });

    test('averageAnnualGrowth()はpublicメソッドとして存在する', function () {
        $mapper = new FundamentalIndicatorMapper;

        expect(method_exists($mapper, 'averageAnnualGrowth'))->toBeTrue();
    });
});
