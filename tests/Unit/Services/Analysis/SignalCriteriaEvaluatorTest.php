<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\SignalCriteriaEvaluator;

/*
|--------------------------------------------------------------------------
| SignalCriteriaEvaluator — Unit Test (UC-004 / UC-010, CHG-0007)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-004業務ルール「判定チェックリスト
|     （2026-08-29追加、CHG-0007）」/ UC-010業務ルール「判定チェックリスト」
|   - docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」
|     表「判定チェックリストの『あと一歩（`near`）』バッファ」行
|     （基準値 T に対し |T|×0.2 手前まで到達で `near`。T=0 の項目は
|      `met`/`unmet` の2値。実測値未取得は `unavailable`）
|   - docs/rcid/traceability-matrix.md CHG-0007
|   - 判定に用いる基準値は既存の確定済み閾値をそのまま可視化する（新設なし）:
|       利確: RSI≧70 / 52週高値からの下落率≦-10% / BB上限乖離≧0% /
|             MACD-シグナル線<0 / PEG≧2.0 / 相対力(対市場)<0
|             （app/Services/Analysis/SignalDeterminationService.php）
|             ＋含み益率>利確ライン（通常+20% / 高水準モード+150%、CHG-0006）
|       買い増し: RSI≦30 / 52週安値からの距離≦+10% / BB下限乖離≦0% /
|             MACD-シグナル線>0 / MA20乖離率≦-10% / PEG≦1.0 / 出来高倍率≧1.5倍
|             （app/Services/Analysis/BuySignalDeterminationService.php）
|       財務健全性（両方）: ROE≧10% / 自己資本比率≧40% / 成長率>0%
|             （app/Services/Analysis/FundamentalHealthEvaluator.php）
|
| App\Services\Analysis\SignalCriteriaEvaluator does not exist yet (pure
| calculation service, no DB/HTTP dependency — same shape as
| FundamentalHealthEvaluator / SignalDeterminationService). Every test below
| is therefore expected to fail with a "Class ... not found" fatal error,
| not an assertion mismatch. That is the intended Red state.
|
| -------------------------------------------------------------------------
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
| -------------------------------------------------------------------------
|   - Constructor takes no arguments (`new SignalCriteriaEvaluator`).
|   - Two public methods:
|       evaluateTakeProfit(array $metrics): array   // UC-004
|       evaluateBuy(array $metrics): array          // UC-010
|   - $metrics is a flat associative array of nullable floats:
|       unrealized_gain_rate, gain_line_threshold (float, e.g. 20.0 / 150.0 —
|       from TakeProfitThresholdEvaluator; used by evaluateTakeProfit only),
|       rsi, current_price, week52_high, week52_low, bb_upper, bb_lower,
|       ma20, macd, macd_signal, volume, volume_ma20,
|       relative_strength_vs_market, peg_ratio, roe, equity_ratio,
|       revenue_growth, operating_income_growth
|     Missing keys are treated the same as null.
|   - Return shape (plain array, matching the other pure-calc services):
|       [
|         'technical'   => list<Row>,   // exactly 7 rows, fixed order
|         'fundamental' => list<Row>,   // exactly 3 rows, fixed order
|         'summary' => [
|           'technical'   => ['met' => int, 'near' => int, 'total' => 7],
|           'fundamental' => ['met' => int, 'near' => int, 'total' => 3],
|         ],
|       ]
|     Row = [
|       'label'           => string,   // e.g. 'RSI'
|       'threshold_label' => string,   // e.g. '≥70'
|       'value_label'     => string,   // e.g. '72.1' — '—' when unavailable
|       'status'          => 'met' | 'near' | 'unmet' | 'unavailable',
|     ]
|   - '成長率' item uses the higher of revenue_growth / operating_income_growth
|     (nulls ignored); unavailable only when BOTH are null.
|   - Derived-percentage items (52週乖離・BB乖離・MA20乖離・出来高倍率) are
|     unavailable when current_price or the reference indicator is null (or
|     the reference is 0, to avoid division by zero).
*/

function signalCriteriaEvaluator(): SignalCriteriaEvaluator
{
    return new SignalCriteriaEvaluator;
}

/**
 * A fully-populated take-profit metrics fixture where EVERY technical item
 * and EVERY fundamental item is comfortably "met" (利確を後押しする方向に
 * 振り切った銘柄).
 *
 * @param  array<string, float|null>  $overrides
 * @return array<string, float|null>
 */
function tpMetricsAllMet(array $overrides = []): array
{
    return array_merge([
        'unrealized_gain_rate' => 94.2,
        'gain_line_threshold' => 20.0,
        'rsi' => 78.0,                        // ≥ 70
        'current_price' => 880.0,
        'week52_high' => 1000.0,              // (880-1000)/1000 = -12% ≤ -10%
        'week52_low' => 400.0,
        'bb_upper' => 850.0,                  // (880-850)/850 = +3.5% ≥ 0%
        'bb_lower' => 700.0,
        'ma20' => 900.0,
        'macd' => -2.0,
        'macd_signal' => 1.0,                 // macd - signal = -3.0 < 0
        'volume' => 3_000_000.0,
        'volume_ma20' => 1_000_000.0,
        'relative_strength_vs_market' => -4.5, // < 0
        'peg_ratio' => 2.6,                    // ≥ 2.0
        'roe' => 15.2,                         // ≥ 10%
        'equity_ratio' => 58.0,               // ≥ 40%
        'revenue_growth' => 8.0,              // > 0%
        'operating_income_growth' => 12.3,
    ], $overrides);
}

/**
 * A fully-populated buy-on-dip metrics fixture where EVERY technical item
 * and EVERY fundamental item is comfortably "met".
 *
 * @param  array<string, float|null>  $overrides
 * @return array<string, float|null>
 */
function buyMetricsAllMet(array $overrides = []): array
{
    return array_merge([
        'rsi' => 22.0,                        // ≤ 30
        'current_price' => 420.0,
        'week52_high' => 1000.0,
        'week52_low' => 400.0,               // (420-400)/400 = +5% ≤ +10%
        'bb_upper' => 900.0,
        'bb_lower' => 450.0,                  // (420-450)/450 = -6.7% ≤ 0%
        'ma20' => 500.0,                      // (420-500)/500 = -16% ≤ -10%
        'macd' => 2.0,
        'macd_signal' => -1.0,               // macd - signal = +3.0 > 0
        'volume' => 2_000_000.0,
        'volume_ma20' => 1_000_000.0,        // ratio 2.0 ≥ 1.5
        'relative_strength_vs_market' => -1.0,
        'peg_ratio' => 0.7,                   // ≤ 1.0
        'roe' => 15.2,
        'equity_ratio' => 58.0,
        'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3,
    ], $overrides);
}

/**
 * Pull one row out of a result group by its 'label'.
 *
 * @param  array<int, array<string, string>>  $rows
 * @return array<string, string>
 */
function criterionRow(array $rows, string $label): array
{
    foreach ($rows as $row) {
        if (($row['label'] ?? null) === $label) {
            return $row;
        }
    }

    throw new \RuntimeException("criterion row not found: {$label}");
}

describe('SignalCriteriaEvaluator: 判定チェックリスト（CHG-0007）', function () {
    describe('共通の返却構造', function () {
        test('evaluateTakeProfit はテクニカル7項目・財務3項目とグループ別サマリを返す', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet());

            expect($result)->toHaveKeys(['technical', 'fundamental', 'summary']);
            expect($result['technical'])->toHaveCount(7);
            expect($result['fundamental'])->toHaveCount(3);
            expect($result['summary']['technical']['total'])->toBe(7);
            expect($result['summary']['fundamental']['total'])->toBe(3);

            foreach ([...$result['technical'], ...$result['fundamental']] as $row) {
                expect($row)->toHaveKeys(['label', 'threshold_label', 'value_label', 'status']);
                expect($row['status'])->toBeIn(['met', 'near', 'unmet', 'unavailable']);
            }
        });

        test('evaluateBuy はテクニカル7項目・財務3項目とグループ別サマリを返す', function () {
            $result = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet());

            expect($result['technical'])->toHaveCount(7);
            expect($result['fundamental'])->toHaveCount(3);
            expect($result['summary']['technical']['total'])->toBe(7);
            expect($result['summary']['fundamental']['total'])->toBe(3);
        });
    });

    describe('利確検討（evaluateTakeProfit）', function () {
        test('全項目を満たす銘柄はテクニカル7/7・財務3/3が met になる', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet());

            expect($result['summary']['technical'])->toMatchArray(['met' => 7, 'near' => 0, 'total' => 7]);
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 3, 'near' => 0, 'total' => 3]);
        });

        test('含み益率の基準ラベルは利確ライン（gain_line_threshold）に追従する', function () {
            $normal = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['gain_line_threshold' => 20.0]));
            $highWater = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'gain_line_threshold' => 150.0,
                'unrealized_gain_rate' => 94.2, // +150%ラインには未達
            ]));

            expect(criterionRow($normal['technical'], '含み益率')['threshold_label'])->toContain('20');
            expect(criterionRow($highWater['technical'], '含み益率')['threshold_label'])->toContain('150');
            // +94.2% は +150% ラインに未達だが、150 の 80%（=120）にも届かないので unmet
            expect(criterionRow($highWater['technical'], '含み益率')['status'])->toBe('unmet');
        });

        test('RSI が基準70の8割（=56）以上70未満なら near、56未満なら unmet', function () {
            $near = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['rsi' => 60.0]));
            $unmet = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['rsi' => 50.0]));
            $exact = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['rsi' => 70.0]));

            expect(criterionRow($near['technical'], 'RSI')['status'])->toBe('near');
            expect(criterionRow($unmet['technical'], 'RSI')['status'])->toBe('unmet');
            expect(criterionRow($exact['technical'], 'RSI')['status'])->toBe('met');
        });

        test('52週高値からの下落率は「≦-10%」の下限方向で判定し、-8%〜-10%は near', function () {
            // current_price=920, week52_high=1000 → -8.0% （-10 の +20% バッファ内）
            $near = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'current_price' => 920.0, 'week52_high' => 1000.0,
            ]));
            // current_price=950 → -5.0% （バッファ外）
            $unmet = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'current_price' => 950.0, 'week52_high' => 1000.0,
            ]));

            $nearRow = criterionRow($near['technical'], '52週高値からの下落率');
            expect($nearRow['status'])->toBe('near');
            expect($nearRow['threshold_label'])->toContain('-10');
            expect(criterionRow($unmet['technical'], '52週高値からの下落率')['status'])->toBe('unmet');
        });

        test('基準が0の項目（BB上限乖離・MACD差・相対力）は near が発生せず met/unmet の2値', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'bb_upper' => 1000.0, 'current_price' => 999.0, // 乖離 -0.1% → 0%基準に僅かに未達
                'macd' => 0.5, 'macd_signal' => 0.0,             // 差 +0.5 → 「<0」に未達
                'relative_strength_vs_market' => 0.3,            // 「<0」に未達
            ]));

            foreach (['ボリンジャー上限乖離', 'MACD-シグナル線', '相対力(対市場)'] as $label) {
                expect(criterionRow($result['technical'], $label)['status'])->toBe('unmet');
            }
            // 「near」は1件も無い
            expect($result['summary']['technical']['near'])->toBe(0);
        });

        test('実測値が取得できない項目は unavailable、value_label は「—」、met/near のどちらにも数えない', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'rsi' => null,
                'peg_ratio' => null,
                'week52_high' => null,
            ]));

            expect(criterionRow($result['technical'], 'RSI')['status'])->toBe('unavailable');
            expect(criterionRow($result['technical'], 'RSI')['value_label'])->toBe('—');
            expect(criterionRow($result['technical'], 'PEGレシオ')['status'])->toBe('unavailable');
            expect(criterionRow($result['technical'], '52週高値からの下落率')['status'])->toBe('unavailable');
            // 3項目 unavailable → 残り4項目が met
            expect($result['summary']['technical']['met'])->toBe(4);
            expect($result['summary']['technical']['near'])->toBe(0);
        });

        test('財務健全性の成長率は売上高・営業利益の高い方で判定し、両方 null のときだけ unavailable', function () {
            $revOnly = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'revenue_growth' => 3.0, 'operating_income_growth' => null,
            ]));
            $bothNull = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'revenue_growth' => null, 'operating_income_growth' => null,
            ]));
            $bothNegative = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'revenue_growth' => -2.0, 'operating_income_growth' => -1.0,
            ]));

            expect(criterionRow($revOnly['fundamental'], '成長率')['status'])->toBe('met');
            expect(criterionRow($bothNull['fundamental'], '成長率')['status'])->toBe('unavailable');
            expect(criterionRow($bothNegative['fundamental'], '成長率')['status'])->toBe('unmet');
        });

        test('ROE・自己資本比率は基準の8割で near（ROE 8〜10 / 自己資本比率 32〜40）', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'roe' => 9.0,           // 10 の 90%
                'equity_ratio' => 35.0, // 40 の 87.5%
            ]));

            expect(criterionRow($result['fundamental'], 'ROE')['status'])->toBe('near');
            expect(criterionRow($result['fundamental'], '自己資本比率')['status'])->toBe('near');
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 1, 'near' => 2, 'total' => 3]);
        });

        // 回帰テスト（`/review`で判明した確定バグの修正、2026-09-05）:
        // 「MACD-シグナル線」はラベル・SignalDeterminationService::
        // determineMacdDeadCross()の双方が厳密な「<0」を要求しているにも
        // かかわらず、classify()には`lte`（≤0）が渡っており、ちょうど0の
        // 境界でSignal未発生のはずが判定チェックリスト上は「met」表示に
        // なってしまっていた。
        test('MACD-シグナル線の差がちょうど0の場合、実際のシグナルは発生しないため unmet になる（境界値の確定バグ修正）', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'macd' => 1.0, 'macd_signal' => 1.0, // 差 = 0.0
            ]));

            expect(criterionRow($result['technical'], 'MACD-シグナル線')['status'])->toBe('unmet');
        });

        // 回帰テスト（`/review`で判明した確定バグの修正、2026-09-05）:
        // 「成長率」はラベル「>0%」・FundamentalHealthEvaluator::evaluate()の
        // 双方が厳密な「>0」を要求しているにもかかわらず、classify()には
        // `gte`（≥0）が渡っており、ちょうど0の境界でfundamental_statusは
        // failedになるはずが判定チェックリスト上は「met」表示になっていた。
        test('成長率がちょうど0%の場合、財務健全性フィルタはfailedになるため unmet になる（境界値の確定バグ修正）', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet([
                'revenue_growth' => 0.0, 'operating_income_growth' => -5.0,
            ]));

            expect(criterionRow($result['fundamental'], '成長率')['status'])->toBe('unmet');
        });
    });

    describe('買い増し候補（evaluateBuy）', function () {
        test('全項目を満たす銘柄はテクニカル7/7・財務3/3が met になる', function () {
            $result = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet());

            expect($result['summary']['technical'])->toMatchArray(['met' => 7, 'near' => 0, 'total' => 7]);
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 3, 'near' => 0, 'total' => 3]);
        });

        test('RSI は「≦30」の上限方向で判定し、30〜36 は near、36超は unmet', function () {
            $exact = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['rsi' => 30.0]));
            $near = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['rsi' => 35.0]));  // 30 + 30*0.2 = 36 以内
            $unmet = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['rsi' => 45.0]));

            expect(criterionRow($exact['technical'], 'RSI')['status'])->toBe('met');
            expect(criterionRow($near['technical'], 'RSI')['status'])->toBe('near');
            expect(criterionRow($unmet['technical'], 'RSI')['status'])->toBe('unmet');
            expect(criterionRow($exact['technical'], 'RSI')['threshold_label'])->toContain('30');
        });

        test('MA20乖離率は負の基準（≦-10%）でも 8割バッファが働き、-8%〜-10%は near', function () {
            // current_price=460, ma20=500 → -8.0%
            $near = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet([
                'current_price' => 460.0, 'ma20' => 500.0,
            ]));
            // current_price=480 → -4.0%
            $unmet = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet([
                'current_price' => 480.0, 'ma20' => 500.0,
            ]));

            expect(criterionRow($near['technical'], 'MA20乖離率')['status'])->toBe('near');
            expect(criterionRow($unmet['technical'], 'MA20乖離率')['status'])->toBe('unmet');
        });

        test('出来高倍率は「≧1.5倍」で判定し、1.2〜1.5倍は near', function () {
            $near = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet([
                'volume' => 1_300_000.0, 'volume_ma20' => 1_000_000.0, // 1.3倍
            ]));
            $unmet = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet([
                'volume' => 1_000_000.0, 'volume_ma20' => 1_000_000.0, // 1.0倍
            ]));

            expect(criterionRow($near['technical'], '出来高倍率')['status'])->toBe('near');
            expect(criterionRow($unmet['technical'], '出来高倍率')['status'])->toBe('unmet');
        });

        test('PEGレシオは「≦1.0」で判定し、1.0〜1.2は near', function () {
            $near = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['peg_ratio' => 1.15]));
            $unmet = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['peg_ratio' => 1.6]));

            expect(criterionRow($near['technical'], 'PEGレシオ')['status'])->toBe('near');
            expect(criterionRow($unmet['technical'], 'PEGレシオ')['status'])->toBe('unmet');
        });

        test('current_price が null のとき派生%項目（52週安値距離・BB下限乖離・MA20乖離）は unavailable', function () {
            $result = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['current_price' => null]));

            foreach (['52週安値からの距離', 'ボリンジャー下限乖離', 'MA20乖離率'] as $label) {
                expect(criterionRow($result['technical'], $label)['status'])->toBe('unavailable');
            }
        });

        // 回帰テスト（`/review`で判明した確定バグの修正、2026-09-05）:
        // 「MACD-シグナル線」はラベル・BuySignalDeterminationService::
        // determineMacdGoldenCross()の双方が厳密な「>0」を要求しているにも
        // かかわらず、classify()には`gte`（≥0）が渡っており、ちょうど0の
        // 境界でSignal未発生のはずが判定チェックリスト上は「met」表示に
        // なってしまっていた。
        test('MACD-シグナル線の差がちょうど0の場合、実際のシグナルは発生しないため unmet になる（境界値の確定バグ修正）', function () {
            $result = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet([
                'macd' => 1.0, 'macd_signal' => 1.0, // 差 = 0.0
            ]));

            expect(criterionRow($result['technical'], 'MACD-シグナル線')['status'])->toBe('unmet');
        });
    });
});
