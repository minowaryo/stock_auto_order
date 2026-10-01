<?php

use App\Services\Concentration\SoxBetaCalculator;

/*
| SoxBetaCalculator — Red phase Unit Test (UC-015 / CHG-0026 Cycle3)
| Expected Red: Class "App\Services\Concentration\SoxBetaCalculator" not found.
*/

test('UC-015: SOXの2倍で動く銘柄のβは2', function () {
    $sox = [0.01, -0.02, 0.03, 0.005, -0.01];
    $betas = (new SoxBetaCalculator)->betas([1 => array_map(fn ($x) => 2 * $x, $sox)], $sox);

    expect($betas[1])->toEqualWithDelta(2.0, 1e-9);
});

test('UC-015: SOXと逆向きに-0.5倍動く銘柄（定数オフセット付き）のβは-0.5', function () {
    $sox = [0.01, -0.02, 0.03, 0.005, -0.01];
    $betas = (new SoxBetaCalculator)->betas([1 => array_map(fn ($x) => -0.5 * $x + 0.02, $sox)], $sox);

    expect($betas[1])->toEqualWithDelta(-0.5, 1e-9);
});

test('UC-015: SOXと無相関の銘柄のβは0', function () {
    $betas = (new SoxBetaCalculator)->betas([1 => [1.0, 1.0, -1.0, -1.0]], [1.0, -1.0, 1.0, -1.0]);

    expect($betas[1])->toEqualWithDelta(0.0, 1e-9);
});

test('UC-015: 定数系列のβは0', function () {
    $betas = (new SoxBetaCalculator)->betas([1 => [0.02, 0.02, 0.02, 0.02]], [0.01, -0.02, 0.03, 0.005]);

    expect($betas[1])->toEqualWithDelta(0.0, 1e-9);
});

test('UC-015: SOXが定数（分散0）なら全銘柄のβがnull', function () {
    $betas = (new SoxBetaCalculator)->betas(
        [1 => [0.01, 0.02, 0.03, 0.04], 2 => [0.0, 0.0, 0.0, 0.0]],
        [0.01, 0.01, 0.01, 0.01],
    );

    expect(array_keys($betas))->toBe([1, 2])
        ->and($betas[1])->toBeNull()
        ->and($betas[2])->toBeNull();
});

test('UC-015: βは標本共分散/標本分散の手計算値と一致しキーは入力を保つ', function () {
    // sox=[1,2,3,4]: mean 2.5, Σdev²=5 ; r=[2,1,4,3]: mean 2.5, Σ dev*dev = 3 → β = 3/5 (T-1 cancels)
    $betas = (new SoxBetaCalculator)->betas([9 => [2.0, 1.0, 4.0, 3.0], 4 => [1.0, 2.0, 3.0, 4.0]], [1.0, 2.0, 3.0, 4.0]);

    expect(array_keys($betas))->toBe([9, 4])
        ->and($betas[9])->toEqualWithDelta(0.6, 1e-9)
        ->and($betas[4])->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: ポートフォリオβは重み付き和（[2,0.5]×[0.25,0.75]=0.875）', function () {
    $beta = (new SoxBetaCalculator)->portfolioBeta([1 => 2.0, 2 => 0.5], [1 => 0.25, 2 => 0.75]);

    expect($beta)->toEqualWithDelta(0.875, 1e-9);
});

test('UC-015: βが空ならポートフォリオβはnull', function () {
    expect((new SoxBetaCalculator)->portfolioBeta([], [1 => 1.0]))->toBeNull();
});

test('UC-015: βにnullが1つでもあればポートフォリオβはnull', function () {
    expect((new SoxBetaCalculator)->portfolioBeta([1 => 2.0, 2 => null], [1 => 0.5, 2 => 0.5]))->toBeNull();
});
