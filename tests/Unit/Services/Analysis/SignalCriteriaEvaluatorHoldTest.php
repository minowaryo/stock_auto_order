<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\SignalCriteriaEvaluator;

/*
|--------------------------------------------------------------------------
| SignalCriteriaEvaluator::evaluateHold() — Unit Test (CHG-0046) — Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/product/use-cases.md UC-013 業務ルール
| 「キープ表の列拡充と判定チェックリストの両方向色分け（CHG-0046）」.
|
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
|   - evaluateHold(array $metrics): array — same $metrics keys as the other
|     evaluate*() methods (plus relative_strength_vs_sector).
|   - technical: 10 items, in order: 含み益率 / RSI / 52週高値からの下落率 /
|     52週安値からの距離 / MA20乖離率 / MACD-シグナル線 / 相対力(対セクター|対市場) /
|     PEGレシオ / PER / PBR.
|   - Each technical item additionally carries `tone`:
|       'warning' = the take-profit-side (UC-004) threshold is met/near (利確寄り)
|       'success' = the buy-side (UC-010) threshold is met/near (押し目寄り)
|       null      = unmet / info / unavailable
|     Precedence: sell met > buy met > sell near > buy near > unmet.
|     Thresholds reuse existing constants (no new thresholds).
|   - fundamental: the same 4 items as UC-004/UC-010 (healthy = met).
|   - summary.technical = {sell: met count with tone warning, buy: met count
|     with tone success, total: 10}; summary.fundamental = {met, near, total}.
|
| evaluateHold() does not exist yet → every test fails with "Call to
| undefined method". That is the intended Red state.
*/

/** Neutral baseline: no item met/near on either side. */
function holdEvalMetrics(array $overrides = []): array
{
    return array_merge([
        'unrealized_gain_rate' => 5.0,
        'rsi' => 45.0,
        'current_price' => 1000.0,
        'week52_high' => 1050.0,   // -4.8%: neither
        'week52_low' => 800.0,     // +25%: neither
        'ma20' => 1000.0,          // 0%: neither
        'macd' => null,
        'macd_signal' => null,
        'relative_strength_vs_market' => 3.0,
        'relative_strength_vs_sector' => null,
        'peg_ratio' => 1.5,        // neither (buy near ≤1.2, sell near ≥1.6)
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 15.2,
        'equity_ratio' => 58.0,
        'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3,
        'operating_margin' => 18.3,
    ], $overrides);
}

function holdEvalItem(array $criteria, string $label): array
{
    foreach (array_merge($criteria['technical'], $criteria['fundamental']) as $item) {
        if ($item['label'] === $label) {
            return $item;
        }
    }

    throw new \RuntimeException("criteria item {$label} not found");
}

describe('CHG-0046: キープ表の判定チェックリスト（evaluateHold）', function () {
    test('テクニカル10項目・財務4項目が決まった順序で並ぶ', function () {
        $criteria = (new SignalCriteriaEvaluator)->evaluateHold(holdEvalMetrics());

        expect(array_column($criteria['technical'], 'label'))->toBe([
            '含み益率', 'RSI', '52週高値からの下落率', '52週安値からの距離', 'MA20乖離率',
            'MACD-シグナル線', '相対力(対市場)', 'PEGレシオ', 'PER', 'PBR',
        ]);
        expect(array_column($criteria['fundamental'], 'label'))->toBe(['ROE', '自己資本比率', '成長率', '営業利益率']);
    });

    test('基準ラベルに利確側・押し目側の両方の閾値が既存定数どおり表示される', function () {
        $criteria = (new SignalCriteriaEvaluator)->evaluateHold(holdEvalMetrics());

        expect(array_column($criteria['technical'], 'threshold_label'))->toBe([
            '利確≥+20%', '利確≥70／押し目≤30', '利確≤-10%', '押し目≤+10%', '押し目≤-10%',
            '利確<0／押し目>0', '利確<0', '利確≥2.0／押し目≤1.0', '', '',
        ]);
    });

    test('RSIは70以上で利確寄り（黄）、30以下で押し目寄り（緑）、あと一歩はそれぞれの淡色になる', function (float $rsi, string $status, ?string $tone) {
        $item = holdEvalItem((new SignalCriteriaEvaluator)->evaluateHold(holdEvalMetrics(['rsi' => $rsi])), 'RSI');

        expect($item['status'])->toBe($status)
            ->and($item['tone'])->toBe($tone)
            ->and($item['value_label'])->toBe(number_format($rsi, 1));
    })->with([
        '利確達成 75' => [75.0, 'met', 'warning'],
        '利確あと一歩 60' => [60.0, 'near', 'warning'],
        '押し目達成 28' => [28.0, 'met', 'success'],
        '押し目あと一歩 33' => [33.0, 'near', 'success'],
        'どちらでもない 45' => [45.0, 'unmet', null],
    ]);

    test('含み益率は利確ライン+20%以上で利確寄りになり、押し目側の基準は持たない', function () {
        $evaluator = new SignalCriteriaEvaluator;

        $met = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['unrealized_gain_rate' => 25.0])), '含み益率');
        $loss = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['unrealized_gain_rate' => -16.0])), '含み益率');

        expect($met['status'])->toBe('met')->and($met['tone'])->toBe('warning')->and($met['value_label'])->toBe('+25.0%');
        expect($loss['status'])->toBe('unmet')->and($loss['tone'])->toBeNull();
    });

    test('含み益率の利確ラインは gain_line_threshold に従い、高水準モード+150%では+98%でも利確寄りにならない', function () {
        // /review 指摘（2026-10-04）: 利確検討と同じく TakeProfitThresholdEvaluator の
        // 利確ラインを渡す。未指定時は通常モード+20%。
        $evaluator = new SignalCriteriaEvaluator;

        $highWater = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['unrealized_gain_rate' => 98.0, 'gain_line_threshold' => 150.0])), '含み益率');
        $nearHighWater = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['unrealized_gain_rate' => 125.0, 'gain_line_threshold' => 150.0])), '含み益率');
        $normal = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['unrealized_gain_rate' => 25.0, 'gain_line_threshold' => 20.0])), '含み益率');

        expect([$highWater['status'], $highWater['tone'], $highWater['threshold_label']])->toBe(['unmet', null, '利確≥+150%']);
        expect([$nearHighWater['status'], $nearHighWater['tone']])->toBe(['near', 'warning']);
        expect([$normal['status'], $normal['tone'], $normal['threshold_label']])->toBe(['met', 'warning', '利確≥+20%']);
    });

    test('MACD-シグナル線はプラスで押し目寄り、マイナスで利確寄りになる', function () {
        $evaluator = new SignalCriteriaEvaluator;

        $plus = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['macd' => 1.0, 'macd_signal' => 0.5])), 'MACD-シグナル線');
        $minus = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['macd' => 0.5, 'macd_signal' => 1.0])), 'MACD-シグナル線');

        expect($plus['status'])->toBe('met')->and($plus['tone'])->toBe('success')->and($plus['value_label'])->toBe('0.50');
        expect($minus['status'])->toBe('met')->and($minus['tone'])->toBe('warning')->and($minus['value_label'])->toBe('-0.50');
    });

    test('PEGは2.0以上で利確寄り、1.0以下の正の値で押し目寄り、負の値はどちらにもならない', function (float $peg, string $status, ?string $tone) {
        $item = holdEvalItem((new SignalCriteriaEvaluator)->evaluateHold(holdEvalMetrics(['peg_ratio' => $peg])), 'PEGレシオ');

        expect($item['status'])->toBe($status)->and($item['tone'])->toBe($tone);
    })->with([
        '割高 2.5' => [2.5, 'met', 'warning'],
        '割安 0.8' => [0.8, 'met', 'success'],
        '負値 -1.0' => [-1.0, 'unmet', null],
    ]);

    test('価格乖離系3項目は既存の利確・押し目閾値で片側だけ色が付く', function () {
        $evaluator = new SignalCriteriaEvaluator;

        // 52週高値 1000 → 現在値 850 = -15.0%（利確≤-10%）
        $high = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['current_price' => 850.0, 'week52_high' => 1000.0])), '52週高値からの下落率');
        // 52週安値 1000 → 現在値 1050 = +5.0%（押し目≤+10%）
        $low = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['current_price' => 1050.0, 'week52_low' => 1000.0])), '52週安値からの距離');
        // MA20 1000 → 現在値 880 = -12.0%（押し目≤-10%）
        $ma = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['current_price' => 880.0, 'ma20' => 1000.0])), 'MA20乖離率');

        expect([$high['status'], $high['tone'], $high['value_label']])->toBe(['met', 'warning', '-15.0%']);
        expect([$low['status'], $low['tone'], $low['value_label']])->toBe(['met', 'success', '+5.0%']);
        expect([$ma['status'], $ma['tone'], $ma['value_label']])->toBe(['met', 'success', '-12.0%']);
    });

    test('相対力は対セクターを優先し、マイナスで利確寄りになる。未算出なら対市場を使う', function () {
        $evaluator = new SignalCriteriaEvaluator;

        $criteria = $evaluator->evaluateHold(holdEvalMetrics(['relative_strength_vs_sector' => -3.0, 'relative_strength_vs_market' => 4.0]));
        $sector = holdEvalItem($criteria, '相対力(対セクター)');
        expect([$sector['status'], $sector['tone'], $sector['value_label']])->toBe(['met', 'warning', '-3.0']);

        $market = holdEvalItem($evaluator->evaluateHold(holdEvalMetrics(['relative_strength_vs_market' => 4.0])), '相対力(対市場)');
        expect([$market['status'], $market['tone']])->toBe(['unmet', null]);
    });

    test('PER・PBRは基準なしの参考表示で、未取得なら「—」になる', function () {
        $evaluator = new SignalCriteriaEvaluator;

        $criteria = $evaluator->evaluateHold(holdEvalMetrics());
        expect([holdEvalItem($criteria, 'PER')['status'], holdEvalItem($criteria, 'PER')['value_label'], holdEvalItem($criteria, 'PER')['tone']])
            ->toBe(['info', '15.0', null]);
        expect([holdEvalItem($criteria, 'PBR')['status'], holdEvalItem($criteria, 'PBR')['value_label']])->toBe(['info', '1.50']);

        $missing = $evaluator->evaluateHold(holdEvalMetrics(['per' => null, 'pbr' => null]));
        expect([holdEvalItem($missing, 'PER')['status'], holdEvalItem($missing, 'PER')['value_label']])->toBe(['unavailable', '—']);
    });

    test('財務4項目は利確検討・買い増し候補と同じ向き（健全＝達成）で判定される', function () {
        $evaluator = new SignalCriteriaEvaluator;

        $healthy = $evaluator->evaluateHold(holdEvalMetrics());
        expect(array_column($healthy['fundamental'], 'status'))->toBe(['met', 'met', 'met', 'met']);
        expect(array_column($healthy['fundamental'], 'value_label'))->toBe(['15.2%', '58.0%', '+12.3%', '18.3%']);

        $weak = $evaluator->evaluateHold(holdEvalMetrics(['roe' => 2.0, 'equity_ratio' => null]));
        expect(holdEvalItem($weak, 'ROE')['status'])->toBe('unmet');
        expect(holdEvalItem($weak, '自己資本比率')['status'])->toBe('unavailable');
    });

    test('サマリは利確寄り・押し目寄りの達成数と財務の達成数を返す', function () {
        $criteria = (new SignalCriteriaEvaluator)->evaluateHold(holdEvalMetrics([
            'rsi' => 75.0,                                   // 利確 met
            'unrealized_gain_rate' => 25.0,                  // 利確 met
            'macd' => 1.0, 'macd_signal' => 0.5,             // 押し目 met
            'peg_ratio' => 1.15,                             // 押し目 near（met には数えない）
            'roe' => 2.0,                                    // 財務 unmet
        ]));

        expect($criteria['summary']['technical'])->toBe(['sell' => 2, 'buy' => 1, 'total' => 10]);
        expect($criteria['summary']['fundamental'])->toBe(['met' => 3, 'near' => 0, 'total' => 4]);
    });

    test('指標が全て未取得でも例外にならず、全項目 unavailable になる', function () {
        $criteria = (new SignalCriteriaEvaluator)->evaluateHold([]);

        expect(array_unique(array_column($criteria['technical'], 'status')))->toBe(['unavailable']);
        expect(array_unique(array_column($criteria['fundamental'], 'status')))->toBe(['unavailable']);
        expect($criteria['summary']['technical'])->toBe(['sell' => 0, 'buy' => 0, 'total' => 10]);
    });
});
