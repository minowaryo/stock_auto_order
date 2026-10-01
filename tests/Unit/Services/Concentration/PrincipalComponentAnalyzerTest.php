<?php

use App\Services\Concentration\EigenSolver;
use App\Services\Concentration\PrincipalComponentAnalyzer;

/*
| PrincipalComponentAnalyzer — Red phase Unit Test (UC-015 / CHG-0026 Cycle3)
| Expected Red: Class "App\Services\Concentration\PrincipalComponentAnalyzer" not found.
*/

/** Deterministic pseudo-random N series × T points. @return array<int, list<float>> */
function pcaTestSeries(int $n, int $t, int $seed): array
{
    $state = $seed;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        for ($k = 0; $k < $t; $k++) {
            $state = ($state * 1103515245 + 12345) % 2147483648;
            $out[$i + 1][$k] = ($state / 2147483648) * 2 - 1;
        }
    }

    return $out;
}

/** Reference: largest eigenvalue of the N×N sample correlation matrix / N. */
function pcaTestReference(array $returns): float
{
    $ids = array_keys($returns);
    $t = count($returns[$ids[0]]);
    $centered = [];
    foreach ($returns as $id => $r) {
        $mean = array_sum($r) / $t;
        $c = array_map(fn ($x) => $x - $mean, $r);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $c)));
        $centered[$id] = array_map(fn ($x) => $x / $norm, $c);
    }
    $corr = [];
    foreach ($ids as $a => $i) {
        foreach ($ids as $b => $j) {
            $s = 0.0;
            for ($k = 0; $k < $t; $k++) {
                $s += $centered[$i][$k] * $centered[$j][$k];
            }
            $corr[$a][$b] = $s;
        }
    }
    $eig = (new EigenSolver)->symmetric($corr);

    return $eig['values'][0] / count($ids);
}

test('UC-015: 同一の2系列の第1主成分寄与率は1.0になる', function () {
    $share = (new PrincipalComponentAnalyzer)->firstComponentShare([1 => [1.0, 3.0, 2.0, 5.0], 2 => [1.0, 3.0, 2.0, 5.0]]);

    expect($share)->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: 完全に無相関な2系列の第1主成分寄与率は0.5になる', function () {
    $share = (new PrincipalComponentAnalyzer)->firstComponentShare([1 => [1.0, -1.0, 1.0, -1.0], 2 => [1.0, 1.0, -1.0, -1.0]]);

    expect($share)->toEqualWithDelta(0.5, 1e-9);
});

test('UC-015: 定数系列は除外され残りの同一2系列で寄与率1.0になる', function () {
    $share = (new PrincipalComponentAnalyzer)->firstComponentShare([
        1 => [2.0, 2.0, 2.0, 2.0],
        2 => [1.0, 3.0, 2.0, 5.0],
        3 => [1.0, 3.0, 2.0, 5.0],
    ]);

    expect($share)->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: 定数系列1本と変動系列1本では非定数が2本未満なのでnull', function () {
    $share = (new PrincipalComponentAnalyzer)->firstComponentShare([1 => [2.0, 2.0, 2.0, 2.0], 2 => [1.0, 3.0, 2.0, 5.0]]);

    expect($share)->toBeNull();
});

test('UC-015: 系列が1本だけならnull', function () {
    expect((new PrincipalComponentAnalyzer)->firstComponentShare([1 => [1.0, 3.0, 2.0, 5.0]]))->toBeNull();
});

test('UC-015: 銘柄数<週数（N=4,T=12）で直接N×N相関行列の固有分解と一致する', function () {
    $returns = pcaTestSeries(4, 12, 42);

    $share = (new PrincipalComponentAnalyzer)->firstComponentShare($returns);

    expect($share)->toEqualWithDelta(pcaTestReference($returns), 1e-9)
        ->and($share)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0 + 1e-9);
});

test('UC-015: 銘柄数>週数（N=8,T=5）でも直接N×N相関行列の固有分解と一致する', function () {
    $returns = pcaTestSeries(8, 5, 7);

    $share = (new PrincipalComponentAnalyzer)->firstComponentShare($returns);

    expect($share)->toEqualWithDelta(pcaTestReference($returns), 1e-9)
        ->and($share)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0 + 1e-9);
});

test('UC-015: 定数系列が混在しても第1主成分寄与率は変動系列だけの相関行列からの直接計算と一致する', function () {
    // Arrange: N=6 varying series (T=10) + 1 constant series
    $varying = pcaTestSeries(6, 10, 99);
    $mixed = $varying;
    $mixed[7] = array_fill(0, 10, 1.5);

    // Act
    $share = (new PrincipalComponentAnalyzer)->firstComponentShare($mixed);

    // Assert
    expect($share)->not->toBeNull();
    expect($share)->toEqualWithDelta(pcaTestReference($varying), 1e-9);
});

test('UC-015: 銘柄数がサンプル数を超える場合（N=40, T=12）でも第1主成分寄与率はN×Nの直接計算と一致する', function () {
    // Arrange
    $returns = pcaTestSeries(40, 12, 31337);

    // Act
    $share = (new PrincipalComponentAnalyzer)->firstComponentShare($returns);

    // Assert
    expect($share)->not->toBeNull();
    expect($share)->toEqualWithDelta(pcaTestReference($returns), 1e-9);
});
