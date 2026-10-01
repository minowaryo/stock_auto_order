<?php

use App\Services\Concentration\EffectiveBetCalculator;
use App\Services\Concentration\EigenSolver;

/*
| EffectiveBetCalculator — Red phase Unit Test (UC-015 / CHG-0026 Cycle3, ADR-0019 D3)
| Expected Red: Class "App\Services\Concentration\EffectiveBetCalculator" not found.
*/

/** @return array<int, list<float>> */
function enbTestSeries(int $n, int $t, int $seed): array
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

/** Entropy-based exp(-Σ p ln p) of a probability list. @param list<float> $p */
function enbTestEntropyExp(array $p): float
{
    $h = 0.0;
    foreach ($p as $x) {
        if ($x > 1e-12) {
            $h -= $x * log($x);
        }
    }

    return exp($h);
}

/** Direct reference from the formula using the N×N sample covariance. */
function enbTestReference(array $returns, array $weights): float
{
    $ids = array_keys($returns);
    $t = count($returns[$ids[0]]);
    $means = [];
    foreach ($returns as $id => $r) {
        $means[$id] = array_sum($r) / $t;
    }
    $cov = [];
    foreach ($ids as $a => $i) {
        foreach ($ids as $b => $j) {
            $s = 0.0;
            for ($k = 0; $k < $t; $k++) {
                $s += ($returns[$i][$k] - $means[$i]) * ($returns[$j][$k] - $means[$j]);
            }
            $cov[$a][$b] = $s / ($t - 1);
        }
    }
    $eig = (new EigenSolver)->symmetric($cov);
    $w = array_values($weights);
    $v = [];
    foreach ($eig['values'] as $idx => $lambda) {
        $proj = 0.0;
        foreach ($eig['vectors'][$idx] as $q => $e) {
            $proj += $e * $w[$q];
        }
        $v[] = max(0.0, $lambda) * $proj * $proj;
    }
    $sum = array_sum($v);

    return enbTestEntropyExp(array_map(fn ($x) => $x / $sum, $v));
}

test('UC-015: 単一銘柄の実効ベット数は1.0', function () {
    $enb = (new EffectiveBetCalculator)->calculate([1 => [1.0, -2.0, 3.0, 0.5]], [1 => 1.0]);

    expect($enb)->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: 無相関で分散比4:1の2銘柄をリスクが等しくなる重み(1/3,2/3)で持つと実効ベット数は2.0', function () {
    // 等分散だと固有値が重なり固有ベクトルの取り方でENBが変わるため、固有値が異なる（16/3と4/3）組でリスク寄与を等しくする
    $enb = (new EffectiveBetCalculator)->calculate(
        [1 => [2.0, -2.0, 2.0, -2.0], 2 => [1.0, 1.0, -1.0, -1.0]],
        [1 => 1 / 3, 2 => 2 / 3],
    );

    expect($enb)->toEqualWithDelta(2.0, 1e-9);
});

test('UC-015: 同一系列の2銘柄を均等保有すると実効ベット数は1.0', function () {
    $enb = (new EffectiveBetCalculator)->calculate(
        [1 => [1.0, -2.0, 3.0, 0.5], 2 => [1.0, -2.0, 3.0, 0.5]],
        [1 => 0.5, 2 => 0.5],
    );

    expect($enb)->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: 無相関で分散比4:1の均等保有はp=(0.8,0.2)のエントロピー指数になる', function () {
    $enb = (new EffectiveBetCalculator)->calculate(
        [1 => [2.0, -2.0, 2.0, -2.0], 2 => [1.0, 1.0, -1.0, -1.0]],
        [1 => 0.5, 2 => 0.5],
    );

    expect($enb)->toEqualWithDelta(enbTestEntropyExp([0.8, 0.2]), 1e-9);
});

test('UC-015: 無相関で分散比4:1のとき重みが0.9/0.1に偏ると実効ベット数は1に近づく', function () {
    // Distinct variances (4s, s) keep the eigenbasis unique; v = (4*0.81, 1*0.01) * s.
    $enb = (new EffectiveBetCalculator)->calculate(
        [1 => [2.0, -2.0, 2.0, -2.0], 2 => [1.0, 1.0, -1.0, -1.0]],
        [1 => 0.9, 2 => 0.1],
    );

    expect($enb)->toEqualWithDelta(enbTestEntropyExp([3.24 / 3.25, 0.01 / 3.25]), 1e-9)
        ->and($enb)->toBeLessThan(1.1);
});

test('UC-015: 完全に相殺するポートフォリオは分散0なのでnull', function () {
    $enb = (new EffectiveBetCalculator)->calculate(
        [1 => [1.0, -1.0, 1.0, -1.0], 2 => [-1.0, 1.0, -1.0, 1.0]],
        [1 => 0.5, 2 => 0.5],
    );

    expect($enb)->toBeNull();
});

test('UC-015: 系列が空ならnull', function () {
    expect((new EffectiveBetCalculator)->calculate([], []))->toBeNull();
});

test('UC-015: 重み全体を定数倍しても実効ベット数は変わらない', function () {
    $returns = enbTestSeries(4, 12, 11);
    $w = [1 => 0.4, 2 => 0.3, 3 => 0.2, 4 => 0.1];
    $w3 = array_map(fn ($x) => $x * 3, $w);

    $calc = new EffectiveBetCalculator;

    expect($calc->calculate($returns, $w3))->toEqualWithDelta($calc->calculate($returns, $w), 1e-9);
});

test('UC-015: 銘柄数<週数（N=4,T=12）で直接N×N共分散の固有分解と一致する', function () {
    $returns = enbTestSeries(4, 12, 42);
    $w = [1 => 0.4, 2 => 0.3, 3 => 0.2, 4 => 0.1];

    $enb = (new EffectiveBetCalculator)->calculate($returns, $w);

    expect($enb)->toEqualWithDelta(enbTestReference($returns, $w), 1e-9)
        ->and($enb)->toBeGreaterThanOrEqual(1.0 - 1e-9)->toBeLessThanOrEqual(min(4, 11) + 1e-9);
});

test('UC-015: 銘柄数>週数（N=8,T=5）のランク落ち共分散でも直接計算と一致する', function () {
    $returns = enbTestSeries(8, 5, 7);
    $w = [1 => 0.25, 2 => 0.2, 3 => 0.15, 4 => 0.12, 5 => 0.1, 6 => 0.08, 7 => 0.06, 8 => 0.04];

    $enb = (new EffectiveBetCalculator)->calculate($returns, $w);

    expect($enb)->not->toBeNull()
        ->and($enb)->toEqualWithDelta(enbTestReference($returns, $w), 1e-9)
        ->and($enb)->toBeGreaterThanOrEqual(1.0 - 1e-9)->toBeLessThanOrEqual(min(8, 4) + 1e-9);
});

test('UC-015: 定数（分散ゼロ）系列が混在しても実効ベット数は有限で、定数行を含む共分散行列からの直接計算と一致する', function () {
    // Arrange: 5 varying series + 1 constant series (weight 0.2), remaining weight split over the others
    $returns = enbTestSeries(5, 10, 777);
    $returns[6] = array_fill(0, 10, 0.5);
    $weights = [1 => 0.1, 2 => 0.2, 3 => 0.25, 4 => 0.15, 5 => 0.1, 6 => 0.2];

    // Act
    $enb = (new EffectiveBetCalculator)->calculate($returns, $weights);

    // Assert
    expect($enb)->not->toBeNull();
    expect(is_finite($enb))->toBeTrue();
    expect($enb)->toEqualWithDelta(enbTestReference($returns, $weights), 1e-9);
});

test('UC-015: 銘柄数がサンプル数を超える場合（N=40, T=12）でも実効ベット数はN×Nの直接計算と一致する', function () {
    // Arrange: non-uniform weights summing to 1
    $returns = enbTestSeries(40, 12, 4242);
    $raw = [];
    for ($i = 1; $i <= 40; $i++) {
        $raw[$i] = 1.0 + ($i % 7);
    }
    $total = array_sum($raw);
    $weights = array_map(fn ($x) => $x / $total, $raw);

    // Act
    $enb = (new EffectiveBetCalculator)->calculate($returns, $weights);

    // Assert
    expect($enb)->not->toBeNull();
    expect($enb)->toEqualWithDelta(enbTestReference($returns, $weights), 1e-9);
});
