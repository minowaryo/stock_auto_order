<?php

use App\Support\SignalOccurrenceMetricLabels;

/*
| SignalOccurrenceMetricLabels — UC-014「根拠値の表示」(CHG-0020 Cycle5)
| MySQL's JSON column reorders object keys on storage, so the display order must
| come from the label map (price → technical → valuation → fundamentals), not
| from the stored key order. Unknown keys follow the known ones.
*/

test('UC-014: 根拠値は保存時のキー順ではなく、定義した項目順（終値→RSI→…）で並ぶ', function () {
    // Arrange: keys in the order MySQL actually returned them (sorted by key length)
    $metrics = ['pbr' => 5.5, 'per' => 15.0, 'roe' => 10.0, 'rsi' => 59.4, 'close' => 607.5, 'ma75_trend_rising' => true];

    // Act
    $items = SignalOccurrenceMetricLabels::format($metrics);

    // Assert
    expect($items)->toBe(['終値: 607.50', 'RSI: 59.40', '75日線上昇: はい', 'PER: 15.00', 'PBR: 5.50', 'ROE: 10.00']);
});

test('UC-014: 未知のキーは既知の項目の後ろに、受け取った順で並ぶ', function () {
    $items = SignalOccurrenceMetricLabels::format(['zeta' => 1, 'per' => 15.0, 'alpha' => 2]);

    expect($items)->toBe(['PER: 15.00', 'zeta: 1.00', 'alpha: 2.00']);
});
