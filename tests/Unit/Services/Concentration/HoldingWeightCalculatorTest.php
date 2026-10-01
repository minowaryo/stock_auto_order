<?php

namespace Tests\Unit\Services\Concentration;

use App\Services\Concentration\HoldingWeightCalculator;

/*
|--------------------------------------------------------------------------
| HoldingWeightCalculator — Red phase Unit Test (UC-015 / CHG-0026 Cycle2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0019-concentration-dashboard.md D3 (上位5銘柄ウェイト),
|     D4 (ウェイト w は計算対象の中で正規化し直す)
|   - docs/product/use-cases.md UC-015 業務ルール
|
| Pure logic (no DB). Contract:
|   marketValues(array $positions): array<int, float>
|     holding_id => quantity * current_price (/ 10000 for mutual_fund,
|     same as SectorAllocationCalculator::evaluationTotal). Numeric
|     strings accepted. Input order preserved.
|   normalize(array<int,float> $marketValues, array<int> $holdingIds): array<int, float>
|     weights over the given subset only, summing to 1.0, keyed by
|     holding_id in $holdingIds order. Unknown ids ignored. Subset total
|     0 or empty subset → [].
|
| Expected Red: Class "App\Services\Concentration\HoldingWeightCalculator" not found.
*/

test('UC-015: 株式・ETFの評価額は数量×現在値で算出される', function () {
    $calculator = new HoldingWeightCalculator;

    $result = $calculator->marketValues([
        ['holding_id' => 1, 'instrument_type' => 'stock', 'quantity' => 100, 'current_price' => 2500.0],
        ['holding_id' => 2, 'instrument_type' => 'etf', 'quantity' => 10, 'current_price' => 30000.5],
    ]);

    expect(array_keys($result))->toBe([1, 2]);
    expect($result[1])->toEqualWithDelta(250000.0, 1e-9);
    expect($result[2])->toEqualWithDelta(300005.0, 1e-9);
});

test('UC-015: 投資信託の評価額は数量×基準価額÷10000で算出される', function () {
    $calculator = new HoldingWeightCalculator;

    $result = $calculator->marketValues([
        ['holding_id' => 7, 'instrument_type' => 'mutual_fund', 'quantity' => 1000000, 'current_price' => 25000],
    ]);

    expect($result[7])->toEqualWithDelta(2500000.0, 1e-9);
});

test('UC-015: DBのdecimal由来の数値文字列も評価額計算に使える', function () {
    $calculator = new HoldingWeightCalculator;

    $result = $calculator->marketValues([
        ['holding_id' => 3, 'instrument_type' => 'stock', 'quantity' => '200.0000', 'current_price' => '1234.5000'],
        ['holding_id' => 4, 'instrument_type' => 'mutual_fund', 'quantity' => '50000', 'current_price' => '12000.00'],
    ]);

    expect($result[3])->toBeFloat();
    expect($result[3])->toEqualWithDelta(246900.0, 1e-9);
    expect($result[4])->toEqualWithDelta(60000.0, 1e-9);
});

test('UC-015: 評価額は入力順のholding_idをキーに保持する', function () {
    $calculator = new HoldingWeightCalculator;

    $result = $calculator->marketValues([
        ['holding_id' => 30, 'instrument_type' => 'stock', 'quantity' => 1, 'current_price' => 1],
        ['holding_id' => 10, 'instrument_type' => 'stock', 'quantity' => 1, 'current_price' => 2],
        ['holding_id' => 20, 'instrument_type' => 'etf', 'quantity' => 1, 'current_price' => 3],
    ]);

    expect(array_keys($result))->toBe([30, 10, 20]);
});

test('UC-015: 保有が空なら評価額も空配列', function () {
    expect((new HoldingWeightCalculator)->marketValues([]))->toBe([]);
});

test('UC-015: ウェイトは計算対象の銘柄の中だけで正規化され合計1になる', function () {
    $calculator = new HoldingWeightCalculator;
    $values = [1 => 100.0, 2 => 300.0, 3 => 600.0, 4 => 1000.0];

    // Holding 4 (e.g. an ETF) is excluded from the computation target.
    $weights = $calculator->normalize($values, [1, 2, 3]);

    expect(array_keys($weights))->toBe([1, 2, 3]);
    expect($weights[1])->toEqualWithDelta(0.1, 1e-9);
    expect($weights[2])->toEqualWithDelta(0.3, 1e-9);
    expect($weights[3])->toEqualWithDelta(0.6, 1e-9);
    expect(array_sum($weights))->toEqualWithDelta(1.0, 1e-9);
});

test('UC-015: ウェイトは指定したholding_idの順序で返る', function () {
    $calculator = new HoldingWeightCalculator;

    $weights = $calculator->normalize([1 => 100.0, 2 => 300.0, 3 => 600.0], [3, 1]);

    expect(array_keys($weights))->toBe([3, 1]);
    expect($weights[3])->toEqualWithDelta(600 / 700, 1e-9);
    expect($weights[1])->toEqualWithDelta(100 / 700, 1e-9);
});

test('UC-015: 評価額に存在しないholding_idはウェイト計算で無視される', function () {
    $calculator = new HoldingWeightCalculator;

    $weights = $calculator->normalize([1 => 100.0, 2 => 100.0], [1, 99, 2]);

    expect(array_keys($weights))->toBe([1, 2]);
    expect($weights[1])->toEqualWithDelta(0.5, 1e-9);
    expect($weights[2])->toEqualWithDelta(0.5, 1e-9);
});

test('UC-015: 計算対象の評価額合計が0ならウェイトは空配列', function () {
    $calculator = new HoldingWeightCalculator;

    expect($calculator->normalize([1 => 0.0, 2 => 0.0, 3 => 500.0], [1, 2]))->toBe([]);
});

test('UC-015: 計算対象が空ならウェイトは空配列', function () {
    $calculator = new HoldingWeightCalculator;

    expect($calculator->normalize([1 => 100.0], []))->toBe([]);
    expect($calculator->normalize([1 => 100.0], [99]))->toBe([]);
});
