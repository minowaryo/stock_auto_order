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
|         'fundamental' => list<Row>,   // exactly 4 rows, fixed order
|                                       //   (CHG-0012 / ADR-0011: 3→4、営業利益率を4項目目に追加)
|         'summary' => [
|           'technical'   => ['met' => int, 'near' => int, 'total' => 7],
|           'fundamental' => ['met' => int, 'near' => int, 'total' => 4],
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
|
| -------------------------------------------------------------------------
| CR (2026-09-06, CHG-0012 / ADR-0011): 財務健全性チェックリストに営業利益率
| を4項目目として追加（fundamental 3行→4行）
| -------------------------------------------------------------------------
| `SignalCriteriaEvaluator::fundamentalRows()` に「営業利益率」行を4項目目
| として追加する。表示レイヤーは `criteria` 配列駆動のため、この1行追加で
| ヘッダー colspan・colgroup・チップセル・サマリ「◯/4」がすべて自動追従する
| （ADR-0011 の「表示のさせ方」節）。
|
|   $metrics キーに `operating_margin`（nullable float）が加わる。
|
|   利確検討（evaluateTakeProfit）/ 買い増し候補（evaluateBuy）:
|     label「営業利益率」、threshold_label「≥10%」、direction 'gte'、
|     threshold = FundamentalHealthEvaluator::MIN_OPERATING_MARGIN (10.0)、
|     フォーマッタ number_format($v, 1).'%'（ROE・自己資本比率と同形式）。
|     near バッファは既存の |T|×0.2 ルール（T=10 → 8.0〜10.0% が near、
|     10.0% 以上で met、8.0% 未満で unmet、null で unavailable）。
|
|   整理検討（evaluateLossReview、ADR-0010 D6 の反転契約に乗せる）:
|     direction を反転（gte→lt）、threshold_label は反転表記（例「<10%」）。
|     10% 未満で met（＝投資根拠の毀損）、near は 10 以上 12 以下、
|     健全（12超）は unmet。値そのものは実測値をそのまま表示。
|
| 現行実装は fundamental が3行のため、Red の出方は:
|   - `toHaveCount(3)` / `total => 3` 等の件数アサーション → 3 vs 4 の不一致
|   - `criterionRow($result['fundamental'], '営業利益率')` → 行が存在せず
|     \RuntimeException("criterion row not found: 営業利益率") で test error
|   - all-met フィクスチャの `met => 3` → 4 の不一致
| いずれも fatal（クラス未検出）ではなくアサーション不一致 / テストエラー。
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
        'operating_margin' => 18.0,           // ≥ 10% (CHG-0012 / ADR-0011)
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
        'operating_margin' => 18.0,           // ≥ 10% (CHG-0012 / ADR-0011)
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
        test('evaluateTakeProfit はテクニカル7項目・財務4項目とグループ別サマリを返す', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet());

            expect($result)->toHaveKeys(['technical', 'fundamental', 'summary']);
            expect($result['technical'])->toHaveCount(7);
            expect($result['fundamental'])->toHaveCount(4);
            expect($result['summary']['technical']['total'])->toBe(7);
            expect($result['summary']['fundamental']['total'])->toBe(4);

            foreach ([...$result['technical'], ...$result['fundamental']] as $row) {
                expect($row)->toHaveKeys(['label', 'threshold_label', 'value_label', 'status']);
                expect($row['status'])->toBeIn(['met', 'near', 'unmet', 'unavailable']);
            }
        });

        test('evaluateBuy はテクニカル7項目・財務4項目とグループ別サマリを返す', function () {
            $result = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet());

            expect($result['technical'])->toHaveCount(7);
            expect($result['fundamental'])->toHaveCount(4);
            expect($result['summary']['technical']['total'])->toBe(7);
            expect($result['summary']['fundamental']['total'])->toBe(4);
        });
    });

    describe('利確検討（evaluateTakeProfit）', function () {
        test('全項目を満たす銘柄はテクニカル7/7・財務4/4が met になる', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet());

            expect($result['summary']['technical'])->toMatchArray(['met' => 7, 'near' => 0, 'total' => 7]);
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 4, 'near' => 0, 'total' => 4]);
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
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 2, 'near' => 2, 'total' => 4]);
        });

        // -------------------------------------------------------------
        // CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率チップ（4項目目）
        // -------------------------------------------------------------
        test('営業利益率は「≥10%」で判定し、10.0%ちょうど→met・9.0%（8割バッファ内）→near・7.0%→unmet・null→unavailable', function () {
            $met = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['operating_margin' => 10.0]));
            $near = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['operating_margin' => 9.0])); // 10 の 90%
            $unmet = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['operating_margin' => 7.0]));
            $unavailable = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['operating_margin' => null]));

            expect(criterionRow($met['fundamental'], '営業利益率')['status'])->toBe('met');
            expect(criterionRow($met['fundamental'], '営業利益率')['threshold_label'])->toContain('10');
            expect(criterionRow($met['fundamental'], '営業利益率')['value_label'])->toBe('10.0%');
            expect(criterionRow($near['fundamental'], '営業利益率')['status'])->toBe('near');
            expect(criterionRow($unmet['fundamental'], '営業利益率')['status'])->toBe('unmet');
            expect(criterionRow($unavailable['fundamental'], '営業利益率')['status'])->toBe('unavailable');
            expect(criterionRow($unavailable['fundamental'], '営業利益率')['value_label'])->toBe('—');
        });

        test('タカラトミー相当（営業利益率9.0%）は near チップだが、財務サマリの met には数えない', function () {
            $result = signalCriteriaEvaluator()->evaluateTakeProfit(tpMetricsAllMet(['operating_margin' => 9.0]));

            expect(criterionRow($result['fundamental'], '営業利益率')['status'])->toBe('near');
            // ROE/自己資本比率/成長率は met のまま → 財務 3/4
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 3, 'near' => 1, 'total' => 4]);
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
        test('全項目を満たす銘柄はテクニカル7/7・財務4/4が met になる', function () {
            $result = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet());

            expect($result['summary']['technical'])->toMatchArray(['met' => 7, 'near' => 0, 'total' => 7]);
            expect($result['summary']['fundamental'])->toMatchArray(['met' => 4, 'near' => 0, 'total' => 4]);
        });

        // CR (2026-09-06, CHG-0012 / ADR-0011): 買い増し候補の営業利益率チップ
        test('買い増し候補でも営業利益率は「≥10%」の順方向で判定される（12.0→met・9.0→near・7.0→unmet・null→unavailable）', function () {
            $met = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['operating_margin' => 12.0]));
            $near = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['operating_margin' => 9.0]));
            $unmet = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['operating_margin' => 7.0]));
            $unavailable = signalCriteriaEvaluator()->evaluateBuy(buyMetricsAllMet(['operating_margin' => null]));

            expect(criterionRow($met['fundamental'], '営業利益率')['status'])->toBe('met');
            expect(criterionRow($near['fundamental'], '営業利益率')['status'])->toBe('near');
            expect(criterionRow($unmet['fundamental'], '営業利益率')['status'])->toBe('unmet');
            expect(criterionRow($unavailable['fundamental'], '営業利益率')['status'])->toBe('unavailable');
            expect(criterionRow($met['fundamental'], '営業利益率')['threshold_label'])->toContain('10');
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

/*
|--------------------------------------------------------------------------
| SignalCriteriaEvaluator::evaluateLossReview() — 追記 (UC-011 / F-011,
| CHG-0010 / ADR-0010 D6 改訂 2026-09-06「赤の単一極性・財務3項目反転」)
|--------------------------------------------------------------------------
|
| ※ この節より上の describe / フィクスチャ関数（tpMetricsAllMet /
|   buyMetricsAllMet / criterionRow / signalCriteriaEvaluator）は一切変更
|   していない。既存の evaluateTakeProfit / evaluateBuy 関連テストは無改変で
|   Green のまま維持される想定。このRedフェーズで書き換えるのは、末尾の
|   describe('整理検討チェックリスト…') 節・フィクスチャ lossMetricsAllMet()・
|   このコメントブロックのみ。
|
| Source of truth:
|   - docs/adr/ADR-0010-loss-review-candidate-list.md D5 / D6（D6 は 2026-09-06 改訂）
|   - docs/product/use-cases.md UC-011 業務ルール「判定チェックリスト（`criteria`）」
|     （2026-09-06 改訂: 財務3項目は判定の向きを反転）
|   - docs/architecture/data-model.md「整理検討の財務健全性3項目」行（2026-09-06 改訂）
|   - docs/product/ui-guidelines.md「判定チェックリストのチップ」/「達成数サマリ」
|     節（整理検討テーブル、2026-09-06 ADR-0010 D6 改訂）
|
| -------------------------------------------------------------------------
| 新契約（ADR-0010 D6 改訂 2026-09-06） — このRedフェーズで固定する仕様
| -------------------------------------------------------------------------
|   evaluateLossReview(array $metrics): array の返却構造は evaluateTakeProfit
|   / evaluateBuy と完全に同一（technical 7 行・固定順 / fundamental 4 行・
|   固定順 / summary は per-group met・near・total）。既存の classify() /
|   fundamentalRows() / row() / percentDeviation() ヘルパーを再利用する。
|
|   $metrics キー（すべて nullable float、キー欠落 == null）:
|     unrealized_gain_rate, current_price, week52_high, week52_low, ma75,
|     macd, macd_signal, relative_strength_vs_market,
|     rebound_buy_signal_count, roe, equity_ratio, revenue_growth,
|     operating_income_growth
|
|   ● テクニカル7項目（固定順）— 契約変更なし。現行実装のまま:
|       1 含み損率              ≤ -20%  (unrealized_gain_rate, lte)
|       2 52週高値からの下落率   ≤ -30%  (percentDeviation(current_price, week52_high), lte)
|       3 52週安値からの距離     ≤ +10%  (percentDeviation(current_price, week52_low), lte)
|       4 MA75乖離率            ≤ -10%  (percentDeviation(current_price, ma75), lte)
|       5 MACD-シグナル線        < 0     (macd - macd_signal, lt)
|       6 相対力(対市場)         ≤ -5    (relative_strength_vs_market, lte)
|       7 押し目買いシグナル件数  = 0件   (rebound_buy_signal_count vs 0, lte)
|     near バッファは既存の NEAR_BUFFER_RATE (0.2)。基準値0の #5 #7 は
|     met/unmet の2値（near なし）。
|
|   ● 財務健全性3項目 — 判定の向きを反転（★ここが ADR-0010 D6 改訂の中身）:
|     「基準割れ（＝投資根拠の毀損）」が成立している状態を met（赤チップ）
|     とする。閾値の値そのものは FundamentalHealthEvaluator の既存定数を流用し
|     新設しない（direction のみ反転）:
|       ROE          : < 10% で met  (MIN_ROE = 10.0, direction lt)
|                      near は 10 以上 12 以下（threshold + |threshold|×0.2）
|                      threshold_label は反転を表す表記（例「<10%」。UC-004/
|                      UC-010 の「≥10%」ではない）
|       自己資本比率  : < 40% で met  (MIN_EQUITY_RATIO = 40.0, direction lt)
|                      near は 40 以上 48 以下 / threshold_label 例「<40%」
|       成長率        : ≤ 0% で met  (MIN_GROWTH_RATE = 0.0, direction lte)
|                      基準値0のため near は発生しない（met/unmet の2値）
|                      成長率は revenue_growth / operating_income_growth の
|                      「高い方」で判定（両方 null のときだけ unavailable）
|                      threshold_label 例「≤0%」
|     いずれの指標も該当値が null のとき unavailable。value_label は unmet でも
|     実測値をそのまま表示する（例 ROE 15.2% → 「15.2%」。「—」は null のとき
|     だけ。現行 row() の挙動そのまま）。
|
|   ● 想定実装（Green フェーズ／Gate 4 で最終確認）:
|     fundamentalRows() に反転フラグ引数（`bool $forLossReview = false` 相当）
|     を追加し、evaluateLossReview() はフラグ true で呼ぶ。
|     evaluateTakeProfit() / evaluateBuy() の呼び出し（フラグ省略＝従来どおり
|     「健全→met」）は無改修。閾値定数の値は FundamentalHealthEvaluator を
|     流用し、direction のみ反転（gte→lt / gt→lte）、threshold_label のみ
|     反転表記に切り替える。この後の別フェーズで実装する。
|
|   ● criteria-chip の tone prop（整理検討テーブルを赤系チップにする）・
|     signal-criteria-summary-badges の「整理シグナル ◯/7」「投資根拠の毀損
|     ◯/3」表記への切替は Blade 側の別フェーズで、この Unit Test の対象外。
|
| evaluateLossReview() 自体は既に実装済み（旧契約: 財務3項目は健全→met）の
| ため、以下の各テストはクラス／メソッド未検出の fatal ではなく、
|   - 健全な財務が met のまま（新契約では unmet 期待）
|   - 毀損した財務が unmet のまま（新契約では met 期待）
|   - ROE の threshold_label が「≥10%」のまま（新契約では「<10%」期待）
|   - 成長率ちょうど0が unmet のまま（新契約では met 期待）
| といったアサーション不一致で失敗する想定。これが意図した Red 状態である。
| （テクニカル7項目のテストは契約変更がないため Green のまま＝回帰確認用。）
*/

/**
 * 「整理を全方向で後押しする」loss-review メトリクスのフィクスチャ。
 * ADR-0010 D6 改訂（2026-09-06、反転契約）に合わせ、財務3項目も「毀損」側に
 * 振り切ってある（テクニカル7/7・財務3/3 がいずれも met になる状態）。
 * current_price = 600 が派生%項目のアンカー。
 *
 * @param  array<string, float|null>  $overrides
 * @return array<string, float|null>
 */
function lossMetricsAllMet(array $overrides = []): array
{
    return array_merge([
        'unrealized_gain_rate' => -38.0,          // ≤ -20
        'current_price' => 600.0,
        'week52_high' => 1000.0,                   // (600-1000)/1000 = -40% ≤ -30%
        'week52_low' => 580.0,                     // (600-580)/580 = +3.4% ≤ +10%
        'ma75' => 750.0,                           // (600-750)/750 = -20% ≤ -10%
        'macd' => -5.0,
        'macd_signal' => -2.0,                     // macd - signal = -3.0 < 0
        'relative_strength_vs_market' => -12.0,    // ≤ -5
        'rebound_buy_signal_count' => 0.0,         // = 0件
        'roe' => 4.2,                              // < 10%  → 毀損＝met（反転契約）
        'equity_ratio' => 28.0,                    // < 40%  → 毀損＝met（反転契約）
        'revenue_growth' => -3.0,                  // ≤ 0%   → 毀損＝met（反転契約）
        'operating_income_growth' => -1.0,         // 高い方でも -1.0 ≤ 0
        'operating_margin' => 6.0,                 // < 10%  → 毀損＝met（反転契約、CHG-0012 / ADR-0011）
    ], $overrides);
}

describe('整理検討チェックリスト（evaluateLossReview、UC-011 / ADR-0010 D6 改訂）', function () {
    test('テクニカル7項目・財務4項目とグループ別サマリを返す（返却構造は evaluateTakeProfit/evaluateBuy と同一）', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet());

        expect($result)->toHaveKeys(['technical', 'fundamental', 'summary']);
        expect($result['technical'])->toHaveCount(7);
        expect($result['fundamental'])->toHaveCount(4);
        expect($result['summary']['technical']['total'])->toBe(7);
        expect($result['summary']['fundamental']['total'])->toBe(4);

        foreach ([...$result['technical'], ...$result['fundamental']] as $row) {
            expect($row)->toHaveKeys(['label', 'threshold_label', 'value_label', 'status']);
            expect($row['status'])->toBeIn(['met', 'near', 'unmet', 'unavailable']);
        }
    });

    test('整理を全方向で後押しする銘柄はテクニカル7/7・財務4/4が met になる（財務は毀損＝met の反転契約）', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet());

        expect($result['summary']['technical'])->toMatchArray(['met' => 7, 'near' => 0, 'total' => 7]);
        expect($result['summary']['fundamental'])->toMatchArray(['met' => 4, 'near' => 0, 'total' => 4]);
    });

    // ---- テクニカル7項目（契約変更なし・現行実装のまま。回帰確認用に維持） ----

    test('含み損率は「≤-20%」で判定し、-20ちょうどは met・-16〜-20は near・-10は unmet', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['unrealized_gain_rate' => -20.0]));
        $near = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['unrealized_gain_rate' => -18.0]));
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['unrealized_gain_rate' => -10.0]));

        expect(criterionRow($met['technical'], '含み損率')['status'])->toBe('met');
        expect(criterionRow($near['technical'], '含み損率')['status'])->toBe('near');
        expect(criterionRow($unmet['technical'], '含み損率')['status'])->toBe('unmet');
        expect(criterionRow($met['technical'], '含み損率')['threshold_label'])->toContain('-20');
    });

    test('52週高値からの下落率は「≤-30%」で判定し、-25%は near・-20%は unmet', function () {
        // current_price=750, week52_high=1000 → -25.0%（-30 の +20% バッファ -24 内）
        $near = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'current_price' => 750.0, 'week52_high' => 1000.0,
        ]));
        // current_price=800 → -20.0%（バッファ外）
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'current_price' => 800.0, 'week52_high' => 1000.0,
        ]));

        $nearRow = criterionRow($near['technical'], '52週高値からの下落率');
        expect($nearRow['status'])->toBe('near');
        expect($nearRow['threshold_label'])->toContain('-30');
        expect(criterionRow($unmet['technical'], '52週高値からの下落率')['status'])->toBe('unmet');
    });

    test('MA75乖離率は「≤-10%」で判定し、参照MAは MA75（-8%は near・-4%は unmet）', function () {
        // current_price=690, ma75=750 → -8.0%
        $near = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'current_price' => 690.0, 'ma75' => 750.0,
        ]));
        // current_price=720 → -4.0%
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'current_price' => 720.0, 'ma75' => 750.0,
        ]));

        expect(criterionRow($near['technical'], 'MA75乖離率')['status'])->toBe('near');
        expect(criterionRow($unmet['technical'], 'MA75乖離率')['status'])->toBe('unmet');
    });

    test('相対力(対市場)は「≤-5」で判定し、-5ちょうどは met・-4.5は near・-3は unmet', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['relative_strength_vs_market' => -5.0]));
        $near = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['relative_strength_vs_market' => -4.5]));
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['relative_strength_vs_market' => -3.0]));

        expect(criterionRow($met['technical'], '相対力(対市場)')['status'])->toBe('met');
        expect(criterionRow($near['technical'], '相対力(対市場)')['status'])->toBe('near');
        expect(criterionRow($unmet['technical'], '相対力(対市場)')['status'])->toBe('unmet');
    });

    test('MACD-シグナル線は「<0」で判定し、差がちょうど0なら unmet（基準値0のため near は発生しない）', function () {
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'macd' => 1.0, 'macd_signal' => 1.0, // 差 = 0.0
        ]));

        expect(criterionRow($unmet['technical'], 'MACD-シグナル線')['status'])->toBe('unmet');
        expect($unmet['summary']['technical']['near'])->toBe(0);
    });

    test('押し目買いシグナル件数は 0件で met・1件以上で unmet（基準値0のため near は発生しない）', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['rebound_buy_signal_count' => 0.0]));
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['rebound_buy_signal_count' => 2.0]));

        expect(criterionRow($met['technical'], '押し目買いシグナル件数')['status'])->toBe('met');
        expect(criterionRow($unmet['technical'], '押し目買いシグナル件数')['status'])->toBe('unmet');
        expect($met['summary']['technical']['near'])->toBe(0);
    });

    test('current_price が null のとき派生%項目（52週高値下落率・52週安値距離・MA75乖離率）は unavailable、value_label は「—」', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['current_price' => null]));

        foreach (['52週高値からの下落率', '52週安値からの距離', 'MA75乖離率'] as $label) {
            $row = criterionRow($result['technical'], $label);
            expect($row['status'])->toBe('unavailable');
            expect($row['value_label'])->toBe('—');
        }
    });

    test('ma75 が null のとき MA75乖離率 のみ unavailable（他項目は継続して判定される）', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['ma75' => null]));

        expect(criterionRow($result['technical'], 'MA75乖離率')['status'])->toBe('unavailable');
        // 残り6テクニカル項目は met のまま
        expect($result['summary']['technical']['met'])->toBe(6);
    });

    // ---- 財務健全性4項目（ADR-0010 D6 改訂: 判定の向きを反転＝毀損で met。
    //      2026-09-06 CHG-0012 / ADR-0011 で営業利益率を4項目目に追加） ----

    test('健全な財務（ROE 15.2% / 自己資本比率 58% / 成長率 +8% / 営業利益率 18%）は財務4項目すべて unmet になり、summary.fundamental.met は 0', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'roe' => 15.2,
            'equity_ratio' => 58.0,
            'revenue_growth' => 8.0,
            'operating_income_growth' => 12.3,
            'operating_margin' => 18.0,
        ]));

        expect(criterionRow($result['fundamental'], 'ROE')['status'])->toBe('unmet');
        expect(criterionRow($result['fundamental'], '自己資本比率')['status'])->toBe('unmet');
        expect(criterionRow($result['fundamental'], '成長率')['status'])->toBe('unmet');
        expect(criterionRow($result['fundamental'], '営業利益率')['status'])->toBe('unmet');
        expect($result['summary']['fundamental'])->toMatchArray(['met' => 0, 'near' => 0, 'total' => 4]);
    });

    // CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率も反転フラグに乗る
    test('営業利益率は「<10%」で met（＝投資根拠の毀損）とし、6.0%→met・11.0%→near・18.0%→unmet。threshold_label は反転表記', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['operating_margin' => 6.0]));
        $near = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['operating_margin' => 11.0])); // 10〜12（threshold + |threshold|×0.2）
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['operating_margin' => 18.0]));

        expect(criterionRow($met['fundamental'], '営業利益率')['status'])->toBe('met');
        expect(criterionRow($near['fundamental'], '営業利益率')['status'])->toBe('near');
        expect(criterionRow($unmet['fundamental'], '営業利益率')['status'])->toBe('unmet');
        // UC-004/UC-010 の「≥10%」ではなく反転表記（例「<10%」）
        expect(criterionRow($met['fundamental'], '営業利益率')['threshold_label'])->toContain('<10');
        // unmet でも実測値をそのまま表示する（「—」は null のときだけ）
        expect(criterionRow($unmet['fundamental'], '営業利益率')['value_label'])->toBe('18.0%');
    });

    test('営業利益率が null（Mapperで異常値null化・JP当期開示なし等）のとき、整理検討テーブルでも営業利益率行は unavailable', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['operating_margin' => null]));

        expect(criterionRow($result['fundamental'], '営業利益率')['status'])->toBe('unavailable');
        expect(criterionRow($result['fundamental'], '営業利益率')['value_label'])->toBe('—');
    });

    test('ROE は「<10%」で met（毀損）とし、4.2%→met・12.0%→near・20.0%→unmet。threshold_label は反転表記', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['roe' => 4.2]));
        $near = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['roe' => 12.0]));
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['roe' => 20.0]));

        expect(criterionRow($met['fundamental'], 'ROE')['status'])->toBe('met');
        expect(criterionRow($near['fundamental'], 'ROE')['status'])->toBe('near');
        expect(criterionRow($unmet['fundamental'], 'ROE')['status'])->toBe('unmet');
        // 「基準割れ＝達成」の向きが読める表記（例「<10%」）。UC-004/UC-010 の「≥10%」ではない。
        expect(criterionRow($met['fundamental'], 'ROE')['threshold_label'])->toContain('<10');
    });

    test('自己資本比率は「<40%」で met（毀損）とし、28.0%→met・60.0%→unmet', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['equity_ratio' => 28.0]));
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet(['equity_ratio' => 60.0]));

        expect(criterionRow($met['fundamental'], '自己資本比率')['status'])->toBe('met');
        expect(criterionRow($unmet['fundamental'], '自己資本比率')['status'])->toBe('unmet');
        expect(criterionRow($met['fundamental'], '自己資本比率')['threshold_label'])->toContain('<40');
    });

    test('成長率は「≤0%」で met（毀損）とし、-3.0%→met・+8.0%→unmet・0.0ちょうど→met。基準値0のため near は発生しない', function () {
        $met = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'revenue_growth' => -3.0, 'operating_income_growth' => -5.0,
        ]));
        $unmet = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'revenue_growth' => 8.0, 'operating_income_growth' => 12.3,
        ]));
        $exactZero = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'revenue_growth' => 0.0, 'operating_income_growth' => -2.0,
        ]));

        expect(criterionRow($met['fundamental'], '成長率')['status'])->toBe('met');
        expect(criterionRow($unmet['fundamental'], '成長率')['status'])->toBe('unmet');
        expect(criterionRow($exactZero['fundamental'], '成長率')['status'])->toBe('met');
        expect($met['summary']['fundamental']['near'])->toBe(0);
        expect($unmet['summary']['fundamental']['near'])->toBe(0);
    });

    test('成長率は売上高・営業利益の「高い方」で反転判定する（+8% と -3% なら健全側 +8% を採り unmet）', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'revenue_growth' => 8.0, 'operating_income_growth' => -3.0,
        ]));

        expect(criterionRow($result['fundamental'], '成長率')['status'])->toBe('unmet');
    });

    test('財務行の value_label は unmet でも実測値をそのまま表示する（「—」は null のときだけ）', function () {
        $healthy = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'roe' => 15.2, 'equity_ratio' => 58.0, 'revenue_growth' => 8.0, 'operating_income_growth' => 12.3,
        ]));

        expect(criterionRow($healthy['fundamental'], 'ROE')['status'])->toBe('unmet');
        expect(criterionRow($healthy['fundamental'], 'ROE')['value_label'])->toBe('15.2%');
        expect(criterionRow($healthy['fundamental'], '自己資本比率')['value_label'])->toBe('58.0%');
        expect(criterionRow($healthy['fundamental'], 'ROE')['value_label'])->not->toBe('—');
    });

    test('roe / equity_ratio が null のとき財務の該当行が unavailable になる（健全な成長率 +8% ・営業利益率 18% は反転で unmet のため met は 0）', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'roe' => null,
            'equity_ratio' => null,
            'revenue_growth' => 8.0,
            'operating_income_growth' => 12.3,
            'operating_margin' => 18.0,
        ]));

        expect(criterionRow($result['fundamental'], 'ROE')['status'])->toBe('unavailable');
        expect(criterionRow($result['fundamental'], '自己資本比率')['status'])->toBe('unavailable');
        expect(criterionRow($result['fundamental'], '成長率')['status'])->toBe('unmet');
        expect(criterionRow($result['fundamental'], '営業利益率')['status'])->toBe('unmet');
        expect($result['summary']['fundamental']['met'])->toBe(0);
    });

    test('revenue_growth / operating_income_growth の両方が null のときだけ 成長率 が unavailable', function () {
        $result = signalCriteriaEvaluator()->evaluateLossReview(lossMetricsAllMet([
            'revenue_growth' => null,
            'operating_income_growth' => null,
        ]));

        expect(criterionRow($result['fundamental'], '成長率')['status'])->toBe('unavailable');
    });
});
