<?php

namespace Tests\Unit\Services\Concentration;

use App\Services\Concentration\WeeklyReturnMatrixBuilder;

/*
|--------------------------------------------------------------------------
| WeeklyReturnMatrixBuilder — Red phase Unit Test (UC-015 / CHG-0026 Cycle2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0019-concentration-dashboard.md D1 (52週・全銘柄共通の窓),
|     D2 (SOX), D4 (計算対象・除外理由)
|   - docs/product/use-cases.md UC-015 業務ルール
|
| Pure logic (no DB). Contract:
|   build(array $candidates, array $closesByHolding, array $soxCloses, int $weeks = 52): array{
|     window_start: ?string, window_end: ?string,
|     returns: array<int, list<float>>, sox_returns: ?list<float>,
|     excluded: array<int, string>}
|   - window_end = latest week_date among STOCK candidates' series.
|   - window = $weeks + 1 consecutive Mondays ending at window_end.
|   - stock included iff positive close on every window Monday.
|   - excluded: non-stock → 'not_stock'; stock w/ gap or close<=0 →
|     'insufficient_history'. Candidate order.
|
| Most fixtures use $weeks = 3 (4 Mondays) to stay readable.
|
| Expected Red: Class "App\Services\Concentration\WeeklyReturnMatrixBuilder" not found.
*/

/**
 * Build [Y-m-d Monday => close] from a start Monday and a list of closes.
 *
 * @param  list<float>  $closes
 * @return array<string, float>
 */
function concentrationSeries(string $startMonday, array $closes): array
{
    $series = [];
    $date = new \DateTimeImmutable($startMonday);
    foreach ($closes as $close) {
        $series[$date->format('Y-m-d')] = $close;
        $date = $date->modify('+7 days');
    }

    return $series;
}

/**
 * @param  list<float>  $expected
 * @param  list<float>  $actual
 */
function expectReturnsClose(array $expected, array $actual): void
{
    expect(array_keys($actual))->toBe(array_keys($expected));
    foreach ($expected as $i => $value) {
        expect($actual[$i])->toEqualWithDelta($value, 1e-9);
    }
}

test('UC-015: 戻り値は窓・リターン・SOXリターン・除外銘柄の5キーを持つ', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [['holding_id' => 1, 'instrument_type' => 'stock']],
        [1 => concentrationSeries('2026-09-07', [100, 110, 99, 99])],
        concentrationSeries('2026-09-07', [1000, 1000, 1100, 1210]),
        3,
    );

    expect(array_keys($result))->toEqualCanonicalizing(['window_start', 'window_end', 'returns', 'sox_returns', 'excluded']);
});

test('UC-015: 窓の終点は株式銘柄の最新週、始点はそこから指定週数さかのぼった月曜', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'stock'],
        ],
        [
            1 => concentrationSeries('2026-08-31', [10, 11, 12, 13, 14]),     // ends 2026-09-28
            2 => concentrationSeries('2026-08-31', [20, 21, 22, 23]),         // ends 2026-09-21
        ],
        [],
        3,
    );

    expect($result['window_end'])->toBe('2026-09-28');
    expect($result['window_start'])->toBe('2026-09-07');
});

test('UC-015: 窓内の全週がそろった株式銘柄の週次リターンが時系列順に算出される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [['holding_id' => 1, 'instrument_type' => 'stock']],
        [1 => concentrationSeries('2026-09-07', [100, 110, 99, 99])],
        [],
        3,
    );

    expect(array_keys($result['returns']))->toBe([1]);
    expectReturnsClose([0.1, -0.1, 0.0], $result['returns'][1]);
    expect($result['excluded'])->toBe([]);
});

test('UC-015: 窓より古い週の終値は無視される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [['holding_id' => 1, 'instrument_type' => 'stock']],
        // 2026-08-24, 08-31 are outside a 4-Monday window ending 09-28.
        [1 => concentrationSeries('2026-08-24', [1, 500, 100, 110, 99, 99])],
        [],
        3,
    );

    expect($result['window_start'])->toBe('2026-09-07');
    expectReturnsClose([0.1, -0.1, 0.0], $result['returns'][1]);
});

test('UC-015: 株式以外（ETF・投資信託）は週足の有無にかかわらずnot_stockで除外される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'etf'],
            ['holding_id' => 3, 'instrument_type' => 'mutual_fund'],
        ],
        [
            1 => concentrationSeries('2026-09-07', [100, 110, 99, 99]),
            2 => concentrationSeries('2026-09-07', [50, 51, 52, 53]),
        ],
        [],
        3,
    );

    expect(array_keys($result['returns']))->toBe([1]);
    expect($result['excluded'])->toBe([2 => 'not_stock', 3 => 'not_stock']);
});

test('UC-015: 株式以外の銘柄の週は窓の終点決定に使われない', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'etf'],
        ],
        [
            1 => concentrationSeries('2026-09-07', [100, 110, 99, 99]),       // ends 09-28
            2 => concentrationSeries('2026-09-14', [50, 51, 52, 53]),         // ends 10-05
        ],
        concentrationSeries('2026-09-14', [1, 2, 3, 4]),                      // SOX ends 10-05
        3,
    );

    expect($result['window_end'])->toBe('2026-09-28');
    expect($result['returns'])->toHaveKey(1);
});

test('UC-015: 窓内の週足が1週でも欠けた株式銘柄はinsufficient_historyで除外される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $gapped = concentrationSeries('2026-09-07', [100, 110, 99, 99]);
    unset($gapped['2026-09-14']);

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'stock'],
            ['holding_id' => 3, 'instrument_type' => 'stock'],
        ],
        [
            1 => concentrationSeries('2026-09-07', [100, 110, 99, 99]),
            2 => $gapped,                                                    // middle gap
            3 => concentrationSeries('2026-09-14', [10, 11, 12]),             // listed < window
        ],
        [],
        3,
    );

    expect(array_keys($result['returns']))->toBe([1]);
    expect($result['excluded'])->toBe([2 => 'insufficient_history', 3 => 'insufficient_history']);
});

test('UC-015: 週足が1件もない株式銘柄はinsufficient_historyで除外される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'stock'],
        ],
        [1 => concentrationSeries('2026-09-07', [100, 110, 99, 99])],
        [],
        3,
    );

    expect($result['excluded'])->toBe([2 => 'insufficient_history']);
});

test('UC-015: 窓内に0以下の終値を含む株式銘柄はinsufficient_historyで除外される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'stock'],
            ['holding_id' => 3, 'instrument_type' => 'stock'],
        ],
        [
            1 => concentrationSeries('2026-09-07', [100, 110, 99, 99]),
            2 => concentrationSeries('2026-09-07', [100, 0, 99, 99]),
            3 => concentrationSeries('2026-09-07', [100, 110, -1, 99]),
        ],
        [],
        3,
    );

    expect(array_keys($result['returns']))->toBe([1]);
    expect($result['excluded'])->toBe([2 => 'insufficient_history', 3 => 'insufficient_history']);
});

test('UC-015: 窓の終点に最新週が届かない株式銘柄はinsufficient_historyで除外される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'stock'],
        ],
        [
            1 => concentrationSeries('2026-09-07', [100, 110, 99, 99]),       // ends 09-28
            2 => concentrationSeries('2026-08-31', [20, 21, 22, 23]),         // ends 09-21 (latest fetch failed)
        ],
        [],
        3,
    );

    expect($result['window_end'])->toBe('2026-09-28');
    expect($result['excluded'])->toBe([2 => 'insufficient_history']);
});

test('UC-015: 除外銘柄と計算対象はいずれも候補の順序を保つ', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $full = fn (float $base) => concentrationSeries('2026-09-07', [$base, $base * 1.1, $base, $base]);

    $result = $builder->build(
        [
            ['holding_id' => 50, 'instrument_type' => 'stock'],
            ['holding_id' => 40, 'instrument_type' => 'etf'],
            ['holding_id' => 30, 'instrument_type' => 'stock'],
            ['holding_id' => 20, 'instrument_type' => 'stock'],
            ['holding_id' => 10, 'instrument_type' => 'mutual_fund'],
        ],
        [
            50 => $full(100),
            30 => concentrationSeries('2026-09-21', [5, 6]),
            20 => $full(200),
        ],
        [],
        3,
    );

    expect(array_keys($result['returns']))->toBe([50, 20]);
    expect($result['excluded'])->toBe([40 => 'not_stock', 30 => 'insufficient_history', 10 => 'not_stock']);
});

test('UC-015: SOXが窓内の全週をそろえていれば同じ窓のリターンが算出される', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [['holding_id' => 1, 'instrument_type' => 'stock']],
        [1 => concentrationSeries('2026-09-07', [100, 110, 99, 99])],
        // Extra older / newer weeks outside the window must be ignored.
        concentrationSeries('2026-08-31', [7, 1000, 1000, 1100, 1210, 5]),
        3,
    );

    expect($result['sox_returns'])->not->toBeNull();
    expectReturnsClose([0.0, 0.1, 0.1], $result['sox_returns']);
});

test('UC-015: SOXの週足が窓内で欠けていればSOXリターンはnull', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $sox = concentrationSeries('2026-09-07', [1000, 1000, 1100, 1210]);
    unset($sox['2026-09-21']);

    $result = $builder->build(
        [['holding_id' => 1, 'instrument_type' => 'stock']],
        [1 => concentrationSeries('2026-09-07', [100, 110, 99, 99])],
        $sox,
        3,
    );

    expect($result['sox_returns'])->toBeNull();
    expect($result['returns'])->toHaveKey(1);
});

test('UC-015: SOXの週足が空ならSOXリターンはnull', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [['holding_id' => 1, 'instrument_type' => 'stock']],
        [1 => concentrationSeries('2026-09-07', [100, 110, 99, 99])],
        [],
        3,
    );

    expect($result['sox_returns'])->toBeNull();
});

test('UC-015: 株式銘柄に週足が1件もなければ窓はnullで全株式がinsufficient_history', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'etf'],
            ['holding_id' => 3, 'instrument_type' => 'stock'],
        ],
        [2 => concentrationSeries('2026-09-07', [50, 51, 52, 53])],
        concentrationSeries('2026-09-07', [1000, 1000, 1100, 1210]),
        3,
    );

    expect($result['window_start'])->toBeNull();
    expect($result['window_end'])->toBeNull();
    expect($result['returns'])->toBe([]);
    expect($result['sox_returns'])->toBeNull();
    expect($result['excluded'])->toBe([1 => 'insufficient_history', 2 => 'not_stock', 3 => 'insufficient_history']);
});

test('UC-015: 候補が空なら窓はnullでリターン・除外とも空', function () {
    $result = (new WeeklyReturnMatrixBuilder)->build([], [], [], 3);

    expect($result['window_start'])->toBeNull();
    expect($result['window_end'])->toBeNull();
    expect($result['returns'])->toBe([]);
    expect($result['sox_returns'])->toBeNull();
    expect($result['excluded'])->toBe([]);
});

test('UC-015: 既定の窓は53個の月曜（52週のリターン）', function () {
    $builder = new WeeklyReturnMatrixBuilder;

    // 60 weeks ending Monday 2026-09-28; closes strictly positive and varying.
    $start = (new \DateTimeImmutable('2026-09-28'))->modify('-'.(59 * 7).' days')->format('Y-m-d');
    $closes = [];
    for ($i = 0; $i < 60; $i++) {
        $closes[] = 100.0 + $i + ($i % 3);
    }
    $series = concentrationSeries($start, $closes);

    // Holding 2 lacks only the oldest window Monday (2025-09-29) → excluded under 52 weeks.
    $short = $series;
    unset($short['2025-09-29']);

    $result = $builder->build(
        [
            ['holding_id' => 1, 'instrument_type' => 'stock'],
            ['holding_id' => 2, 'instrument_type' => 'stock'],
        ],
        [1 => $series, 2 => $short],
        $series,
    );

    expect($result['window_end'])->toBe('2026-09-28');
    expect($result['window_start'])->toBe('2025-09-29');
    expect($result['returns'][1])->toHaveCount(52);
    expect($result['sox_returns'])->toHaveCount(52);
    expect($result['excluded'])->toBe([2 => 'insufficient_history']);

    // First return = close(2025-10-06) / close(2025-09-29) - 1 (indices 8 and 7 of the 60-week series).
    expect($result['returns'][1][0])->toEqualWithDelta($closes[8] / $closes[7] - 1, 1e-9);
    expect($result['returns'][1][51])->toEqualWithDelta($closes[59] / $closes[58] - 1, 1e-9);
});
