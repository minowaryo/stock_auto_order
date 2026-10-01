<?php

use App\Services\Concentration\TopHoldingsConcentrationCalculator;

/*
| TopHoldingsConcentrationCalculator — Red phase Unit Test (UC-015 / CHG-0026 Cycle3)
| Expected Red: Class "App\Services\Concentration\TopHoldingsConcentrationCalculator" not found.
*/

test('UC-015: 7銘柄の上位5銘柄の比率は250/280', function () {
    $w = (new TopHoldingsConcentrationCalculator)->topWeight([1 => 10, 2 => 20, 3 => 30, 4 => 40, 5 => 50, 6 => 60, 7 => 70]);

    expect($w)->toEqualWithDelta(250 / 280, 1e-9);
});

test('UC-015: 保有がn銘柄未満なら上位比率は1.0', function () {
    expect((new TopHoldingsConcentrationCalculator)->topWeight([1 => 10, 2 => 20, 3 => 30]))->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: nを指定すると上位n銘柄の比率になる（n=2）', function () {
    $w = (new TopHoldingsConcentrationCalculator)->topWeight([1 => 10, 2 => 20, 3 => 30, 4 => 40], 2);

    expect($w)->toEqualWithDelta(70 / 100, 1e-9);
});

test('UC-015: 同額の銘柄があっても上位n銘柄の比率が求まる', function () {
    $w = (new TopHoldingsConcentrationCalculator)->topWeight([1 => 10, 2 => 10, 3 => 10, 4 => 10], 2);

    expect($w)->toEqualWithDelta(0.5, 1e-9);
});

test('UC-015: 評価額合計が0ならnull', function () {
    expect((new TopHoldingsConcentrationCalculator)->topWeight([1 => 0, 2 => 0]))->toBeNull();
});

test('UC-015: 保有が空ならnull', function () {
    expect((new TopHoldingsConcentrationCalculator)->topWeight([]))->toBeNull();
});

test('UC-015: 入力の並び順は結果に影響しない', function () {
    $calc = new TopHoldingsConcentrationCalculator;

    expect($calc->topWeight([7 => 70, 1 => 10, 4 => 40, 2 => 20, 6 => 60, 3 => 30, 5 => 50]))
        ->toEqualWithDelta($calc->topWeight([1 => 10, 2 => 20, 3 => 30, 4 => 40, 5 => 50, 6 => 60, 7 => 70]), 1e-12);
});
