<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\TechnicalIndicatorCalculator;

/*
|--------------------------------------------------------------------------
| TechnicalIndicatorCalculator — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
|   - docs/architecture/data-model.md (`technical_indicators` table)
|
| App\Services\Analysis\TechnicalIndicatorCalculator does not exist yet
| (no file under app/Services/Analysis/ at all). Every test below is
| expected to fail with a fatal "Class \"App\Services\Analysis\
| TechnicalIndicatorCalculator\" not found" error at the `new
| TechnicalIndicatorCalculator()` line inside the Act step — this is the
| intentional, expected Red state (same convention as
| tests/Feature/UC003HoldingDetailTest.php before HoldingMemo existed).
|
| This class is pure calculation logic (no DB/HTTP dependency per the
| task description), so this is a Unit Test with no RefreshDatabase.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - RSI(14週) uses the simple-average-based RS formula (not Wilder's
|     smoothing), matching the task description. When avg_loss = 0 (all
|     up-weeks in the trailing 14-week window), this test assumes the
|     conventional RSI = 100 (division-by-zero guard), which is the
|     universally documented convention for this edge case; flag if a
|     different convention (e.g. null) is preferred.
|   - EMA(12week)/EMA(26week)/EMA(9week-of-MACD) are assumed to be seeded
|     with the simple moving average of the first `period` values (a
|     standard, widely-used EMA seeding convention), then recursed with
|     alpha = 2/(period+1). All MACD/macd_signal fixtures below use a
|     strictly linear weekly close series (step = +1/week), for which the
|     EMA lag is a mathematically exact constant offset regardless of the
|     seeding index (a property of EMA over a linear input) — so the
|     expected values (macd = 7.0, macd_signal = 7.0) hold for *any*
|     reasonable seeding convention, not just the SMA-seeded one assumed
|     here. This was chosen deliberately to keep the value assertions
|     robust to that particular implementation detail while still using
|     concrete, calculator-verified numbers instead of only checking
|     "not null" (verified via an independent reference script, see PR
|     description / Gate 4 notes).
|   - Standard deviation for the Bollinger Bands is the *sample* standard
|     deviation (n-1 denominator), per the task description.
|   - `calculate([])` (empty price history) returns an array with all
|     documented keys present and set to null, rather than throwing.
|
*/

/**
 * Builds a strictly linear weekly price series: close_i = $startClose + i,
 * volume_i = $startVolume + i * 10 (i = 0..count-1, ascending/oldest-first).
 * Used for MA20/MA75/BB/volume/volume_ma20/week52 high-low/relative
 * strength/MACD fixtures, all of which have hand-verifiable closed-form
 * expected values for an arithmetic sequence with common difference 1.
 *
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function ticArithmeticPriceHistory(int $count, float $startClose = 100.0, int $startVolume = 1000, string $startDate = '2024-01-01'): array
{
    $history = [];
    $date = new \DateTimeImmutable($startDate);

    for ($i = 0; $i < $count; $i++) {
        $history[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => $startClose + $i,
            'volume' => $startVolume + $i * 10,
        ];
    }

    return $history;
}

/**
 * Builds a 15-week (14-diff) series with exactly 7 up-weeks of +1 followed
 * by 7 down-weeks of -1 (100 -> 107 -> 100), giving a hand-computable
 * RSI(14) of exactly 50.0 (avg_gain = avg_loss = 0.5, RS = 1,
 * RSI = 100 - 100/(1+1) = 50). Truncating to fewer weeks is used for the
 * insufficient-data boundary case.
 *
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function ticRsiPriceHistory(int $count = 15): array
{
    $closes = [100, 101, 102, 103, 104, 105, 106, 107, 106, 105, 104, 103, 102, 101, 100];
    $closes = array_slice($closes, 0, $count);

    $history = [];
    $date = new \DateTimeImmutable('2024-01-01');

    foreach ($closes as $i => $close) {
        $history[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => 1000,
        ];
    }

    return $history;
}

const TIC_ALL_KEYS = [
    'rsi', 'macd', 'macd_signal', 'ma20', 'ma75', 'bb_upper', 'bb_lower',
    'volume', 'volume_ma20', 'week52_high', 'week52_low',
    'relative_strength_vs_market', 'relative_strength_vs_sector',
];

// -----------------------------------------------------------------------
// 正常系: 十分なデータ（80週分の等差数列, close = 100..179）
// -----------------------------------------------------------------------

test('MA20とMA75を正しく算出できる（80週分のデータ）', function () {
    $priceHistory = ticArithmeticPriceHistory(80);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory);

    // last 20 closes = 160..179 (mean)
    expect($result['ma20'])->toBe(169.5);
    // last 75 closes = 105..179 (mean)
    expect($result['ma75'])->toBe(142.0);
});

test('ボリンジャーバンド（20週, ±2σ）を正しく算出できる（80週分のデータ）', function () {
    $priceHistory = ticArithmeticPriceHistory(80);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory);

    // mean = 169.5, sample stdev of 20 consecutive integers = sqrt(35) = 5.91607978309962
    $expectedUpper = 169.5 + 2 * sqrt(35);
    $expectedLower = 169.5 - 2 * sqrt(35);

    expect(abs($result['bb_upper'] - $expectedUpper))->toBeLessThan(0.0001);
    expect(abs($result['bb_lower'] - $expectedLower))->toBeLessThan(0.0001);
});

test('出来高と出来高20週平均を正しく算出できる（80週分のデータ）', function () {
    $priceHistory = ticArithmeticPriceHistory(80);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory);

    // last week's volume = 1000 + 79*10
    expect($result['volume'])->toBe(1790);
    // last 20 volumes = 1600..1790 (mean)
    expect($result['volume_ma20'])->toBe(1695.0);
});

test('52週高値・安値を正しく算出できる（80週分のデータ）', function () {
    $priceHistory = ticArithmeticPriceHistory(80);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory);

    // last 52 closes = 128..179 (strictly increasing)
    expect($result['week52_high'])->toBe(179.0);
    expect($result['week52_low'])->toBe(128.0);
});

test('MACDとMACDシグナルを正しく算出できる（80週分のデータ）', function () {
    $priceHistory = ticArithmeticPriceHistory(80);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory);

    // For a linear weekly series (step = +1), EMA12 - EMA26 lag is an exact
    // constant offset: (26-1)/2 - (12-1)/2 = 7.0. The 9-week EMA of a
    // constant 7.0 series is trivially 7.0 too.
    expect(abs($result['macd'] - 7.0))->toBeLessThan(0.0001);
    expect(abs($result['macd_signal'] - 7.0))->toBeLessThan(0.0001);
});

test('相対力（対市場・対セクター）を正しく算出できる（80週分のデータ）', function () {
    $priceHistory = ticArithmeticPriceHistory(80);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory, marketReturn13w: 3.0, sectorReturn13w: 1.5);

    // 13-week stock return = close(80)/close(67) - 1 = 179/166 - 1 = 7.83132530120482 %
    $stockReturn13w = (179 / 166 - 1) * 100;

    expect(abs($result['relative_strength_vs_market'] - ($stockReturn13w - 3.0)))->toBeLessThan(0.0001);
    expect(abs($result['relative_strength_vs_sector'] - ($stockReturn13w - 1.5)))->toBeLessThan(0.0001);
});

test('RSI(14週)を正しく算出できる（7週上昇+7週下落のデータ）', function () {
    $priceHistory = ticRsiPriceHistory(15);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory);

    // avg_gain = avg_loss = 0.5 -> RS = 1 -> RSI = 100 - 100/(1+1) = 50
    expect($result['rsi'])->toBe(50.0);
});

// -----------------------------------------------------------------------
// 境界値・異常系
// -----------------------------------------------------------------------

test('MA20はデータが19件だとnull、20件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(19));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(20));

    expect($insufficient['ma20'])->toBeNull();
    // 20 consecutive closes 100..119, mean = 109.5
    expect($sufficient['ma20'])->toBe(109.5);
});

test('MA75はデータが74件だとnull、75件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(74));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(75));

    expect($insufficient['ma75'])->toBeNull();
    // 75 consecutive closes 100..174, mean = 137.0
    expect($sufficient['ma75'])->toBe(137.0);
});

test('ボリンジャーバンドはデータが19件だとnull、20件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(19));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(20));

    expect($insufficient['bb_upper'])->toBeNull();
    expect($insufficient['bb_lower'])->toBeNull();

    // mean = 109.5, sample stdev = sqrt(35) (invariant of the starting value)
    $expectedUpper = 109.5 + 2 * sqrt(35);
    $expectedLower = 109.5 - 2 * sqrt(35);

    expect(abs($sufficient['bb_upper'] - $expectedUpper))->toBeLessThan(0.0001);
    expect(abs($sufficient['bb_lower'] - $expectedLower))->toBeLessThan(0.0001);
});

test('出来高20週平均はデータが19件だとnull、20件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(19));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(20));

    expect($insufficient['volume_ma20'])->toBeNull();
    // 20 consecutive volumes 1000..1190, mean = 1095
    expect($sufficient['volume_ma20'])->toBe(1095.0);
});

test('52週高値・安値はデータが51件だとnull、52件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(51));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(52));

    expect($insufficient['week52_high'])->toBeNull();
    expect($insufficient['week52_low'])->toBeNull();

    // 52 consecutive closes 100..151
    expect($sufficient['week52_high'])->toBe(151.0);
    expect($sufficient['week52_low'])->toBe(100.0);
});

test('MACDはデータが25件だとnull、26件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(25));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(26));

    expect($insufficient['macd'])->toBeNull();
    expect($insufficient['macd_signal'])->toBeNull();

    expect(abs($sufficient['macd'] - 7.0))->toBeLessThan(0.0001);
});

test('MACDシグナルはデータが34件だとnull、35件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(34));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticArithmeticPriceHistory(35));

    // macd itself is already computable at 34 weeks (>= 26), only the
    // signal line should be null here.
    expect(abs($insufficient['macd'] - 7.0))->toBeLessThan(0.0001);
    expect($insufficient['macd_signal'])->toBeNull();

    expect(abs($sufficient['macd_signal'] - 7.0))->toBeLessThan(0.0001);
});

test('RSIはデータが14件だとnull、15件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(ticRsiPriceHistory(14));
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(ticRsiPriceHistory(15));

    expect($insufficient['rsi'])->toBeNull();
    expect($sufficient['rsi'])->toBe(50.0);
});

test('相対力はデータが13件だとnull、14件だと算出される', function () {
    $insufficient = (new TechnicalIndicatorCalculator)->calculate(
        ticArithmeticPriceHistory(13),
        marketReturn13w: 5.0,
        sectorReturn13w: 10.0,
    );
    $sufficient = (new TechnicalIndicatorCalculator)->calculate(
        ticArithmeticPriceHistory(14),
        marketReturn13w: 5.0,
        sectorReturn13w: 10.0,
    );

    expect($insufficient['relative_strength_vs_market'])->toBeNull();
    expect($insufficient['relative_strength_vs_sector'])->toBeNull();

    // 14 consecutive closes 100..113: stock 13-week return = 113/100 - 1 = 13.0%
    expect($sufficient['relative_strength_vs_market'])->toBe(8.0); // 13.0 - 5.0
    expect($sufficient['relative_strength_vs_sector'])->toBe(3.0); // 13.0 - 10.0
});

test('市場騰落率がnullの場合は対市場の相対力もnullになる（対セクターは算出される）', function () {
    $priceHistory = ticArithmeticPriceHistory(14);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory, marketReturn13w: null, sectorReturn13w: 10.0);

    expect($result['relative_strength_vs_market'])->toBeNull();
    expect($result['relative_strength_vs_sector'])->toBe(3.0); // 13.0 - 10.0
});

test('セクター騰落率がnullの場合は対セクターの相対力もnullになる（対市場は算出される）', function () {
    $priceHistory = ticArithmeticPriceHistory(14);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory, marketReturn13w: 5.0, sectorReturn13w: null);

    expect($result['relative_strength_vs_market'])->toBe(8.0); // 13.0 - 5.0
    expect($result['relative_strength_vs_sector'])->toBeNull();
});

test('市場・セクター騰落率が両方nullの場合は相対力が両方nullになる', function () {
    $priceHistory = ticArithmeticPriceHistory(14);

    $result = (new TechnicalIndicatorCalculator)->calculate($priceHistory, marketReturn13w: null, sectorReturn13w: null);

    expect($result['relative_strength_vs_market'])->toBeNull();
    expect($result['relative_strength_vs_sector'])->toBeNull();
});

// -----------------------------------------------------------------------
// 空配列
// -----------------------------------------------------------------------

test('空の価格系列を渡した場合は例外を投げず全項目nullで返る', function () {
    $result = (new TechnicalIndicatorCalculator)->calculate([], marketReturn13w: 5.0, sectorReturn13w: 10.0);

    expect($result)->toHaveKeys(TIC_ALL_KEYS);

    foreach (TIC_ALL_KEYS as $key) {
        expect($result[$key])->toBeNull();
    }
});
