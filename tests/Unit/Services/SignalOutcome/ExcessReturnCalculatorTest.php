<?php

use App\Services\SignalOutcome\ExcessReturnCalculator;

/*
| ExcessReturnCalculator — Red phase Unit Test (UC-014 / CHG-0020 Cycle3, ADR-0017 D4/D5)
| Expected Red: Class "App\Services\SignalOutcome\ExcessReturnCalculator" not found.
|
| Week keys are Monday 'Y-m-d' (WeekDateNormalizer). targetWeek = observedWeek + 7 * horizonWeeks days.
| A bar whose week >= asOfWeek belongs to the still-in-progress week and is not final.
*/

// observed 2026-01-05 → +4: 2026-02-02 / +13: 2026-04-06 / +26: 2026-07-06
const ERC_OBSERVED = '2026-01-05';
const ERC_AS_OF = '2026-09-28';

test('UC-014: +4週の超過リターンは銘柄リターン−指数リターン（10% − 5% = 5ポイント）', function () {
    // Arrange
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 1100.0];
    $index = ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0];

    // Act
    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, ERC_AS_OF);

    // Assert
    expect($result['status'])->toBe('matured');
    expect($result['stock_return'])->toEqualWithDelta(10.0, 1e-9);
    expect($result['index_return'])->toEqualWithDelta(5.0, 1e-9);
    expect($result['excess_return'])->toEqualWithDelta(5.0, 1e-9);
});

test('UC-014: +13週は発生週から13週後の終値を使う', function () {
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 5000.0, '2026-04-06' => 1200.0, '2026-07-06' => 9000.0];
    $index = ['2026-01-05' => 100.0, '2026-02-02' => 500.0, '2026-04-06' => 110.0, '2026-07-06' => 900.0];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 13, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('matured');
    expect($result['stock_return'])->toEqualWithDelta(20.0, 1e-9);
    expect($result['index_return'])->toEqualWithDelta(10.0, 1e-9);
    expect($result['excess_return'])->toEqualWithDelta(10.0, 1e-9);
});

test('UC-014: +26週は発生週から26週後の終値を使う', function () {
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 5000.0, '2026-04-06' => 5000.0, '2026-07-06' => 1300.0];
    $index = ['2026-01-05' => 100.0, '2026-02-02' => 500.0, '2026-04-06' => 500.0, '2026-07-06' => 105.0];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 26, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('matured');
    expect($result['stock_return'])->toEqualWithDelta(30.0, 1e-9);
    expect($result['index_return'])->toEqualWithDelta(5.0, 1e-9);
    expect($result['excess_return'])->toEqualWithDelta(25.0, 1e-9);
});

test('UC-014: 銘柄が指数を下回れば超過リターンはマイナス', function () {
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 950.0];
    $index = ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('matured');
    expect($result['stock_return'])->toEqualWithDelta(-5.0, 1e-9);
    expect($result['excess_return'])->toEqualWithDelta(-10.0, 1e-9);
});

test('UC-014: 到達週が進行中の週（asOfWeekと同じ）なら行が存在しても結果待ち', function () {
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 1100.0];
    $index = ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, '2026-02-02');

    expect($result)->toBe([
        'status' => 'pending',
        'stock_return' => null,
        'index_return' => null,
        'excess_return' => null,
    ]);
});

test('UC-014: 到達週がasOfWeekより後なら結果待ち', function () {
    $stock = ['2026-01-05' => 1000.0];
    $index = ['2026-01-05' => 30000.0];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 26, $stock, $index, '2026-03-02');

    expect($result['status'])->toBe('pending');
    expect($result['excess_return'])->toBeNull();
});

test('UC-014: 到達週がasOfWeekの1週前なら確定済みとして算出する（境界）', function () {
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 1100.0];
    $index = ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0];

    // asOfWeek = targetWeek + 7 days
    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, '2026-02-09');

    expect($result['status'])->toBe('matured');
    expect($result['excess_return'])->toEqualWithDelta(5.0, 1e-9);
});

dataset('missing endpoints', [
    '銘柄の発生週が欠損' => [['2026-02-02' => 1100.0], ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0]],
    '銘柄の到達週が欠損' => [['2026-01-05' => 1000.0], ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0]],
    '指数の発生週が欠損' => [['2026-01-05' => 1000.0, '2026-02-02' => 1100.0], ['2026-02-02' => 31500.0]],
    '指数の到達週が欠損' => [['2026-01-05' => 1000.0, '2026-02-02' => 1100.0], ['2026-01-05' => 30000.0]],
    '指数の系列がまったくない' => [['2026-01-05' => 1000.0, '2026-02-02' => 1100.0], []],
]);

test('UC-014: 始点・終点の価格が欠けていれば算出不可', function (array $stock, array $index) {
    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, ERC_AS_OF);

    expect($result)->toBe([
        'status' => 'unavailable',
        'stock_return' => null,
        'index_return' => null,
        'excess_return' => null,
    ]);
})->with('missing endpoints');

dataset('non-positive closes', [
    '銘柄の発生週が0' => [['2026-01-05' => 0.0, '2026-02-02' => 1100.0], ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0]],
    '銘柄の到達週が0' => [['2026-01-05' => 1000.0, '2026-02-02' => 0.0], ['2026-01-05' => 30000.0, '2026-02-02' => 31500.0]],
    '指数の発生週が0' => [['2026-01-05' => 1000.0, '2026-02-02' => 1100.0], ['2026-01-05' => 0.0, '2026-02-02' => 31500.0]],
    '指数の到達週が負' => [['2026-01-05' => 1000.0, '2026-02-02' => 1100.0], ['2026-01-05' => 30000.0, '2026-02-02' => -1.0]],
]);

test('UC-014: 始点・終点の終値が0以下なら算出不可', function (array $stock, array $index) {
    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('unavailable');
    expect($result['stock_return'])->toBeNull();
    expect($result['index_return'])->toBeNull();
    expect($result['excess_return'])->toBeNull();
})->with('non-positive closes');

test('UC-014: 途中の週が欠けていても始点・終点があれば算出できる', function () {
    // Only endpoints exist; intermediate weeks 2026-01-12..2026-01-26 are missing.
    $stock = ['2026-01-05' => 1000.0, '2026-02-02' => 1100.0];
    $index = ['2026-01-05' => 30000.0, '2026-01-19' => 99999.0, '2026-02-02' => 31500.0];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('matured');
    expect($result['excess_return'])->toEqualWithDelta(5.0, 1e-9);
});

test('UC-014: 無関係な週の行や並び順は結果に影響しない', function () {
    $stock = [
        '2026-03-02' => 7777.0,
        '2026-02-02' => 1100.0,
        '2025-12-29' => 1.0,
        '2026-01-05' => 1000.0,
        '2026-01-12' => 5555.0,
    ];
    $index = [
        '2026-02-02' => 31500.0,
        '2026-02-09' => 1.0,
        '2026-01-05' => 30000.0,
        '2025-12-29' => 99999.0,
    ];

    $result = (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 4, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('matured');
    expect($result['stock_return'])->toEqualWithDelta(10.0, 1e-9);
    expect($result['index_return'])->toEqualWithDelta(5.0, 1e-9);
    expect($result['excess_return'])->toEqualWithDelta(5.0, 1e-9);
});

test('UC-014: 分割遡及調整済みの系列なら分割をまたいでも小さなリターンになる（NTT 9432 相当、ADR-0017 D5）', function () {
    // The calculator relies on split-adjusted closes from weekly_prices (Yahoo quote.close is
    // retroactively split-adjusted). It must never be fed unadjusted CSV prices
    // (holding_snapshots.current_price): around NTT 9432's 25:1 split (2023-06-25 record date)
    // the unadjusted series jumps ~4025 -> ~165, which would read as ≈ -96% instead of ≈ +2.5%.
    $stock = [
        '2023-06-05' => 161.0,
        '2023-06-12' => 162.0,
        '2023-06-19' => 161.5,
        '2023-06-26' => 163.0,
        '2023-07-03' => 165.0,
    ];
    $index = ['2023-06-05' => 32000.0, '2023-07-03' => 32000.0];

    $result = (new ExcessReturnCalculator)->calculate('2023-06-05', 4, $stock, $index, ERC_AS_OF);

    expect($result['status'])->toBe('matured');
    expect($result['stock_return'])->toEqualWithDelta((165 / 161 - 1) * 100, 1e-9); // ≈ +2.48%
    expect($result['stock_return'])->toBeGreaterThan(0.0);
    expect($result['excess_return'])->toEqualWithDelta((165 / 161 - 1) * 100, 1e-9);
});

test('UC-014: 評価期間が0週なら例外', function () {
    (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, 0, ['2026-01-05' => 1000.0], ['2026-01-05' => 30000.0], ERC_AS_OF);
})->throws(InvalidArgumentException::class);

test('UC-014: 評価期間が負なら例外', function () {
    (new ExcessReturnCalculator)->calculate(ERC_OBSERVED, -4, ['2026-01-05' => 1000.0], ['2026-01-05' => 30000.0], ERC_AS_OF);
})->throws(InvalidArgumentException::class);
