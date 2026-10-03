<?php

use App\Services\SignalOutcome\OutcomeStatisticsCalculator;

/*
| OutcomeStatisticsCalculator — Red phase Unit Test (UC-014 / CHG-0020 Cycle3, ADR-0017 D7)
| Expected Red: Class "App\Services\SignalOutcome\OutcomeStatisticsCalculator" not found.
|
| Input: matured excess returns (%). t = mean / (s / sqrt(n)), s = sample standard deviation (n-1).
| Hit rate (%): buy / watchlist_buy → share > 0, take_profit → share < 0; exactly 0 is a miss.
*/

test('UC-014: [2,4,6,8] の平均・中央値・t値・的中率を算出する', function () {
    // Arrange: s^2 = (9+1+1+9)/3 = 20/3, t = 5 / (s / sqrt(4))
    $s = sqrt(20 / 3);

    // Act
    $stats = (new OutcomeStatisticsCalculator)->calculate([2.0, 4.0, 6.0, 8.0], 'buy');

    // Assert
    expect($stats['matured_count'])->toBe(4);
    expect($stats['mean'])->toEqualWithDelta(5.0, 1e-9);
    expect($stats['median'])->toEqualWithDelta(5.0, 1e-9);
    expect($stats['t_value'])->toEqualWithDelta(5 / ($s / 2), 1e-9); // ≈ 3.873
    expect($stats['hit_rate'])->toEqualWithDelta(100.0, 1e-9);
});

test('UC-014: 結果到来が0件なら件数0・他はnull', function () {
    $stats = (new OutcomeStatisticsCalculator)->calculate([], 'buy');

    expect($stats)->toBe([
        'matured_count' => 0,
        'mean' => null,
        'median' => null,
        't_value' => null,
        'hit_rate' => null,
    ]);
});

test('UC-014: 1件なら平均・中央値はその値でt値はnull', function () {
    $stats = (new OutcomeStatisticsCalculator)->calculate([3.5], 'buy');

    expect($stats['matured_count'])->toBe(1);
    expect($stats['mean'])->toEqualWithDelta(3.5, 1e-9);
    expect($stats['median'])->toEqualWithDelta(3.5, 1e-9);
    expect($stats['t_value'])->toBeNull();
    expect($stats['hit_rate'])->toEqualWithDelta(100.0, 1e-9);
});

test('UC-014: 全件同じ値（標準偏差0）ならt値はnull', function () {
    $stats = (new OutcomeStatisticsCalculator)->calculate([2.0, 2.0, 2.0], 'buy');

    expect($stats['matured_count'])->toBe(3);
    expect($stats['mean'])->toEqualWithDelta(2.0, 1e-9);
    expect($stats['t_value'])->toBeNull();
});

test('UC-014: 件数が奇数なら中央値は真ん中の値（入力順に依存しない）', function () {
    $stats = (new OutcomeStatisticsCalculator)->calculate([9.0, -1.0, 3.0, 100.0, 2.0], 'buy');

    expect($stats['median'])->toEqualWithDelta(3.0, 1e-9);
    expect($stats['mean'])->toEqualWithDelta(113 / 5, 1e-9);
});

test('UC-014: 件数が偶数なら中央値は真ん中2つの平均（入力順に依存しない）', function () {
    $stats = (new OutcomeStatisticsCalculator)->calculate([10.0, -4.0, 1.0, 3.0], 'buy');

    expect($stats['median'])->toEqualWithDelta(2.0, 1e-9);
});

test('UC-014: 平均がマイナスならt値もマイナス', function () {
    $s = sqrt(20 / 3);

    $stats = (new OutcomeStatisticsCalculator)->calculate([-2.0, -4.0, -6.0, -8.0], 'take_profit');

    expect($stats['mean'])->toEqualWithDelta(-5.0, 1e-9);
    expect($stats['t_value'])->toEqualWithDelta(-5 / ($s / 2), 1e-9);
});

test('UC-014: 的中率は買い系はプラス、利確系はマイナスの割合で数える', function () {
    // 3 positive, 1 negative
    $values = [1.0, 2.0, 3.0, -1.0];
    $calc = new OutcomeStatisticsCalculator;

    expect($calc->calculate($values, 'buy')['hit_rate'])->toEqualWithDelta(75.0, 1e-9);
    expect($calc->calculate($values, 'watchlist_buy')['hit_rate'])->toEqualWithDelta(75.0, 1e-9);
    expect($calc->calculate($values, 'take_profit')['hit_rate'])->toEqualWithDelta(25.0, 1e-9);
});

test('UC-014: 超過リターンがちょうど0の発生は買い系・利確系とも外れとして数える', function () {
    $values = [0.0, 1.0, -1.0, 0.0];
    $calc = new OutcomeStatisticsCalculator;

    expect($calc->calculate($values, 'buy')['hit_rate'])->toEqualWithDelta(25.0, 1e-9);
    expect($calc->calculate($values, 'take_profit')['hit_rate'])->toEqualWithDelta(25.0, 1e-9);
});

test('UC-014: 未知のsourceは例外', function () {
    (new OutcomeStatisticsCalculator)->calculate([1.0, 2.0], 'sell');
})->throws(InvalidArgumentException::class);
