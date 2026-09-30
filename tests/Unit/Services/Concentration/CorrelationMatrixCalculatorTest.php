<?php

use App\Services\Concentration\CorrelationMatrixCalculator;

/*
| CorrelationMatrixCalculator — Red phase Unit Test (UC-015 / CHG-0026 Cycle3)
| Expected Red: Class "App\Services\Concentration\CorrelationMatrixCalculator" not found.
*/

test('UC-015: 同一の系列どうしの相関は1になる', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([1 => [1.0, 2.0, 4.0, 3.0], 2 => [1.0, 2.0, 4.0, 3.0]]);

    expect($m[1][2])->toEqualWithDelta(1.0, 1e-9)
        ->and($m[2][1])->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: 符号反転した系列との相関は-1になる', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([1 => [1.0, 2.0, 4.0, 3.0], 2 => [-1.0, -2.0, -4.0, -3.0]]);

    expect($m[1][2])->toEqualWithDelta(-1.0, 1e-9);
});

test('UC-015: 系列[1,2,3,4]と[2,1,4,3]の相関は0.6になる', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([1 => [1.0, 2.0, 3.0, 4.0], 2 => [2.0, 1.0, 4.0, 3.0]]);

    expect($m[1][2])->toEqualWithDelta(0.6, 1e-9);
});

test('UC-015: 相関行列は対称で対角成分は1になる', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([
        1 => [1.0, 2.0, 3.0, 4.0, 1.5],
        2 => [2.0, 1.0, 4.0, 3.0, 0.5],
        3 => [0.3, -0.2, 0.9, 0.1, 0.7],
    ]);

    foreach ([1, 2, 3] as $i) {
        expect($m[$i][$i])->toEqualWithDelta(1.0, 1e-9);
        foreach ([1, 2, 3] as $j) {
            expect($m[$i][$j])->toEqualWithDelta($m[$j][$i], 1e-12);
        }
    }
});

test('UC-015: 定数系列の行・列（対角含む）はnullで他の組は影響を受けない', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([
        1 => [1.0, 2.0, 3.0, 4.0],
        2 => [5.0, 5.0, 5.0, 5.0],
        3 => [2.0, 1.0, 4.0, 3.0],
    ]);

    expect($m[2][1])->toBeNull()
        ->and($m[2][2])->toBeNull()
        ->and($m[2][3])->toBeNull()
        ->and($m[1][2])->toBeNull()
        ->and($m[3][2])->toBeNull()
        ->and($m[1][1])->toEqualWithDelta(1.0, 1e-9)
        ->and($m[3][3])->toEqualWithDelta(1.0, 1e-9)
        ->and($m[1][3])->toEqualWithDelta(0.6, 1e-9);
});

test('UC-015: 行列のキーは入力順・入力IDのまま保持される', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([
        30 => [1.0, 2.0, 3.0],
        10 => [3.0, 1.0, 2.0],
        20 => [1.0, 3.0, 2.0],
    ]);

    expect(array_keys($m))->toBe([30, 10, 20])
        ->and(array_keys($m[30]))->toBe([30, 10, 20])
        ->and(array_keys($m[20]))->toBe([30, 10, 20]);
});

test('UC-015: 系列が1本だけなら[[1.0]]（そのID）を返す', function () {
    $m = (new CorrelationMatrixCalculator)->calculate([7 => [1.0, 2.0, 4.0]]);

    expect(array_keys($m))->toBe([7])
        ->and($m[7][7])->toEqualWithDelta(1.0, 1e-9);
});
