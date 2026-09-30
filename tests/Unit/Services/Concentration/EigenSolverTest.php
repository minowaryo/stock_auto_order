<?php

namespace Tests\Unit\Services\Concentration;

use App\Services\Concentration\EigenSolver;

/*
|--------------------------------------------------------------------------
| EigenSolver — Red phase Unit Test (UC-015 / CHG-0026 Cycle2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0019-concentration-dashboard.md D3
|     (固有値分解は対称行列用のJacobi法を自前実装。双対形のグラム行列に適用)
|
| Contract:
|   symmetric(array $matrix): array{values: list<float>, vectors: list<list<float>>}
|   - values sorted descending; vectors[k] is the unit eigenvector (n floats)
|     for values[k]. Vector sign unspecified → assertions are sign-agnostic.
|   - [] → ['values' => [], 'vectors' => []].
| Tests only check results/properties, not the algorithm.
|
| Expected Red: Class "App\Services\Concentration\EigenSolver" not found.
*/

const EIGEN_TOL = 1e-9;

/** @param list<float> $a @param list<float> $b */
function eigenDot(array $a, array $b): float
{
    $sum = 0.0;
    foreach ($a as $i => $v) {
        $sum += $v * $b[$i];
    }

    return $sum;
}

/** @param list<list<float>> $m @param list<float> $v @return list<float> */
function eigenMatVec(array $m, array $v): array
{
    return array_map(fn (array $row) => eigenDot($row, $v), $m);
}

/** @param list<list<float>> $x (rows × cols) @return list<list<float>> XᵀX (cols × cols) */
function eigenGram(array $x): array
{
    $cols = count($x[0]);
    $g = [];
    for ($i = 0; $i < $cols; $i++) {
        for ($j = 0; $j < $cols; $j++) {
            $s = 0.0;
            foreach ($x as $row) {
                $s += $row[$i] * $row[$j];
            }
            $g[$i][$j] = $s;
        }
    }

    return $g;
}

/** Deterministic pseudo-random rows × cols matrix in [-1, 1]. @return list<list<float>> */
function eigenDeterministicMatrix(int $rows, int $cols, int $seed): array
{
    $state = $seed;
    $m = [];
    for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
            $state = ($state * 1103515245 + 12345) % 2147483648;
            $m[$i][$j] = ($state / 2147483648) * 2 - 1;
        }
    }

    return $m;
}

/** Sign-agnostic: |<actual, expected/|expected|>| ≈ 1. @param list<float> $actual @param list<float> $expected */
function expectParallel(array $actual, array $expected): void
{
    $norm = sqrt(eigenDot($expected, $expected));
    $unit = array_map(fn ($v) => $v / $norm, $expected);
    expect(abs(eigenDot($actual, $unit)))->toEqualWithDelta(1.0, 1e-9);
}

/**
 * A·v_k ≈ λ_k·v_k, orthonormal vectors, descending values, Σλ ≈ trace(A).
 *
 * @param  list<list<float>>  $matrix
 * @param  array{values: list<float>, vectors: list<list<float>>}  $result
 */
function expectValidEigenDecomposition(array $matrix, array $result, float $tol): void
{
    $n = count($matrix);
    expect($result['values'])->toHaveCount($n);
    expect($result['vectors'])->toHaveCount($n);

    for ($k = 1; $k < $n; $k++) {
        expect($result['values'][$k - 1])->toBeGreaterThanOrEqual($result['values'][$k] - $tol);
    }

    $trace = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $trace += $matrix[$i][$i];
    }
    expect(array_sum($result['values']))->toEqualWithDelta($trace, $tol);

    foreach ($result['vectors'] as $k => $v) {
        expect($v)->toHaveCount($n);
        $av = eigenMatVec($matrix, $v);
        foreach ($av as $i => $component) {
            expect($component)->toEqualWithDelta($result['values'][$k] * $v[$i], $tol);
        }
        foreach ($result['vectors'] as $l => $w) {
            expect(eigenDot($v, $w))->toEqualWithDelta($k === $l ? 1.0 : 0.0, $tol);
        }
    }
}

test('UC-015: 空行列の固有分解は固有値・固有ベクトルとも空', function () {
    $result = (new EigenSolver)->symmetric([]);

    expect($result)->toBe(['values' => [], 'vectors' => []]);
});

test('UC-015: 1×1行列の固有値はその要素で固有ベクトルは長さ1', function () {
    $result = (new EigenSolver)->symmetric([[5.5]]);

    expect($result['values'])->toHaveCount(1);
    expect($result['values'][0])->toEqualWithDelta(5.5, EIGEN_TOL);
    expect($result['vectors'])->toHaveCount(1);
    expect(abs($result['vectors'][0][0]))->toEqualWithDelta(1.0, EIGEN_TOL);
});

test('UC-015: 対角行列の固有値は対角成分を降順に並べたもの', function () {
    $result = (new EigenSolver)->symmetric([
        [2.0, 0.0, 0.0],
        [0.0, 7.0, 0.0],
        [0.0, 0.0, -1.0],
    ]);

    expect($result['values'][0])->toEqualWithDelta(7.0, EIGEN_TOL);
    expect($result['values'][1])->toEqualWithDelta(2.0, EIGEN_TOL);
    expect($result['values'][2])->toEqualWithDelta(-1.0, EIGEN_TOL);
    expectParallel($result['vectors'][0], [0, 1, 0]);
    expectParallel($result['vectors'][1], [1, 0, 0]);
    expectParallel($result['vectors'][2], [0, 0, 1]);
});

test('UC-015: 2×2行列[[2,1],[1,2]]の固有値は3と1で固有ベクトルは(1,1)と(1,-1)方向', function () {
    $result = (new EigenSolver)->symmetric([[2, 1], [1, 2]]);

    expect($result['values'][0])->toEqualWithDelta(3.0, EIGEN_TOL);
    expect($result['values'][1])->toEqualWithDelta(1.0, EIGEN_TOL);
    expectParallel($result['vectors'][0], [1, 1]);
    expectParallel($result['vectors'][1], [1, -1]);
});

test('UC-015: 3×3行列[[4,1,0],[1,3,1],[0,1,2]]の固有値は3+√3・3・3-√3', function () {
    // Characteristic roots derived by hand: det(A - 3I) = 0, det(A) = 18 = 3·(3+√3)(3-√3), trace = 9.
    $matrix = [[4, 1, 0], [1, 3, 1], [0, 1, 2]];

    $result = (new EigenSolver)->symmetric($matrix);

    expect($result['values'][0])->toEqualWithDelta(3 + sqrt(3), EIGEN_TOL);
    expect($result['values'][1])->toEqualWithDelta(3.0, EIGEN_TOL);
    expect($result['values'][2])->toEqualWithDelta(3 - sqrt(3), EIGEN_TOL);
    // A·(1,-1,-1) = (3,-3,-3) = 3·(1,-1,-1)
    expectParallel($result['vectors'][1], [1, -1, -1]);
    expectValidEigenDecomposition($matrix, $result, EIGEN_TOL);
});

test('UC-015: 5×5の対称行列で固有方程式・正規直交性・固有値の和=トレースが成り立つ', function () {
    $matrix = [
        [4.0, -1.2, 0.5, 2.1, 0.0],
        [-1.2, 3.3, 1.7, -0.4, 0.9],
        [0.5, 1.7, -2.0, 0.3, 1.1],
        [2.1, -0.4, 0.3, 1.5, -0.8],
        [0.0, 0.9, 1.1, -0.8, 0.7],
    ];

    $result = (new EigenSolver)->symmetric($matrix);

    expectValidEigenDecomposition($matrix, $result, EIGEN_TOL);
});

test('UC-015: 20×20の半正定値行列（AᵀA）でも固有分解の性質が成り立ち固有値は非負', function () {
    $matrix = eigenGram(eigenDeterministicMatrix(30, 20, 42));

    $result = (new EigenSolver)->symmetric($matrix);

    expectValidEigenDecomposition($matrix, $result, 1e-8);
    foreach ($result['values'] as $value) {
        expect($value)->toBeGreaterThanOrEqual(-1e-9);
    }
});

test('UC-015: ランク落ちの半正定値行列（列数>行数のXᵀX）は0に近い固有値を-1e-9以上で返す', function () {
    // X is 3 × 6 → XᵀX is 6 × 6 with rank ≤ 3, so at least 3 eigenvalues are ~0.
    $matrix = eigenGram(eigenDeterministicMatrix(3, 6, 7));

    $result = (new EigenSolver)->symmetric($matrix);

    expectValidEigenDecomposition($matrix, $result, 1e-8);
    foreach ($result['values'] as $value) {
        expect($value)->toBeGreaterThanOrEqual(-1e-9);
    }
    foreach (array_slice($result['values'], 3) as $value) {
        expect(abs($value))->toBeLessThan(1e-8);
    }
    expect($result['values'][2])->toBeGreaterThan(1e-6);
});
