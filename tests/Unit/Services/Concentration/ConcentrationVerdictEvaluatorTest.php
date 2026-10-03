<?php

use App\Services\Concentration\ConcentrationVerdictEvaluator;

/*
| ConcentrationVerdictEvaluator — Red phase Unit Test (UC-015 / CHG-0030, ADR-0021 D2/D3)
| Boundary values belong to the WORSE side.
| Expected Red: Class "App\Services\Concentration\ConcentrationVerdictEvaluator" not found.
*/

dataset('concVerdictPc1', [
    'null' => [null, null],
    '0' => [0.0, 'ok'],
    '24.99' => [24.99, 'ok'],
    '25.0' => [25.0, 'caution'],
    '44.99' => [44.99, 'caution'],
    '45.0' => [45.0, 'concentrated'],
    '100' => [100.0, 'concentrated'],
]);

test('UC-015: PC1寄与率は25%・45%を境に判定され、境界値は悪い側になる', function (?float $input, ?string $expected) {
    expect((new ConcentrationVerdictEvaluator)->pc1($input))->toBe($expected);
})->with('concVerdictPc1');

dataset('concVerdictEnb', [
    'null' => [null, null],
    '12' => [12.0, 'ok'],
    '8.0' => [8.0, 'ok'],
    '7.99' => [7.99, 'caution'],
    '3.0' => [3.0, 'caution'],
    '2.99' => [2.99, 'concentrated'],
    '1.0' => [1.0, 'concentrated'],
]);

test('UC-015: 実効ベット数は8・3を境に判定され、境界値は悪い側になる', function (?float $input, ?string $expected) {
    expect((new ConcentrationVerdictEvaluator)->effectiveBets($input))->toBe($expected);
})->with('concVerdictEnb');

dataset('concVerdictTop5', [
    'null' => [null, null],
    '0' => [0.0, 'ok'],
    '29.99' => [29.99, 'ok'],
    '30.0' => [30.0, 'caution'],
    '49.99' => [49.99, 'caution'],
    '50.0' => [50.0, 'concentrated'],
    '100' => [100.0, 'concentrated'],
]);

test('UC-015: 上位5銘柄ウェイトは30%・50%を境に判定され、境界値は悪い側になる', function (?float $input, ?string $expected) {
    expect((new ConcentrationVerdictEvaluator)->top5($input))->toBe($expected);
})->with('concVerdictTop5');

dataset('concVerdictBeta', [
    'null' => [null, null],
    '0' => [0.0, 'ok'],
    '0.49' => [0.49, 'ok'],
    '0.5' => [0.5, 'caution'],
    '0.79' => [0.79, 'caution'],
    '0.8' => [0.8, 'concentrated'],
    '2.5' => [2.5, 'concentrated'],
    '-0.49' => [-0.49, 'ok'],
    '-0.5' => [-0.5, 'caution'],
    '-0.79' => [-0.79, 'caution'],
    '-0.8' => [-0.8, 'concentrated'],
    '-2.5' => [-2.5, 'concentrated'],
]);

test('UC-015: 対SOXベータは絶対値で0.5・0.8を境に判定され、負の値も対称に判定される', function (?float $input, ?string $expected) {
    expect((new ConcentrationVerdictEvaluator)->soxBeta($input))->toBe($expected);
})->with('concVerdictBeta');

dataset('concVerdictCorrelation', [
    'null' => [null, null],
    '1' => [1.0, 'strong'],
    '0.7' => [0.7, 'strong'],
    '0.69' => [0.69, 'high'],
    '0.5' => [0.5, 'high'],
    '0.49' => [0.49, 'mild'],
    '0.3' => [0.3, 'mild'],
    '0.29' => [0.29, 'none'],
    '0' => [0.0, 'none'],
    '-0.29' => [-0.29, 'none'],
    '-0.3' => [-0.3, 'negative'],
    '-1' => [-1.0, 'negative'],
]);

test('UC-015: 相関係数は0.7・0.5・0.3・-0.3を境に5区分され、境界値は強い側（悪い側）になる', function (?float $input, ?string $expected) {
    expect((new ConcentrationVerdictEvaluator)->correlationBand($input))->toBe($expected);
})->with('concVerdictCorrelation');

dataset('concVerdictNonFinite', [
    'NAN' => [NAN],
    'INF' => [INF],
    '-INF' => [-INF],
]);

test('UC-015: NAN・INF・-INF はどの指標でも判定せず null を返す（ADR-0021 D2）', function (float $input) {
    $evaluator = new ConcentrationVerdictEvaluator;

    expect($evaluator->pc1($input))->toBeNull();
    expect($evaluator->effectiveBets($input))->toBeNull();
    expect($evaluator->top5($input))->toBeNull();
    expect($evaluator->soxBeta($input))->toBeNull();
    expect($evaluator->correlationBand($input))->toBeNull();
})->with('concVerdictNonFinite');
