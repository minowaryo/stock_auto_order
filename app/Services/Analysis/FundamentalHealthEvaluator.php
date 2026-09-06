<?php

namespace App\Services\Analysis;

/**
 * Evaluates the fundamental health filter (UC-010, ADR-0007 D4): same
 * threshold values as NewCandidateFinder::MIN_EQUITY_RATIO/MIN_ROE (UC-008/
 * UC-009), designed as a general-purpose class rather than buy-only
 * (ADR-0007 D4).
 *
 * Pure calculation logic only — no DB/HTTP dependency.
 */
final class FundamentalHealthEvaluator
{
    /**
     * 財務健全性フィルタ (data-model.md「買い増し用ファンダメンタルズ健全性
     * フィルタ」: 自己資本比率40%以上・ROE10%以上、NewCandidateFinderと同一値).
     */
    public const MIN_EQUITY_RATIO = 40.0;

    public const MIN_ROE = 10.0;

    /**
     * 成長率条件（CHG-0005）の基準値。0.0固定だが、判定チェックリスト
     * （2026-08-29、CHG-0007、App\Services\Analysis\SignalCriteriaEvaluator）
     * が参照する単一のソースにするため定数化する。
     */
    public const MIN_GROWTH_RATE = 0.0;

    /**
     * 営業利益率条件（CHG-0012 / ADR-0011 D2）の基準値。財務健全性フィルタの
     * 4条件目。ROE の 10% と数字を揃え、8%案との実測比較の末に採用した叩き台
     * （8%案は財務 passed 27→25、10%案は 27→22。境界9〜10%の3銘柄を切ることを
     * 許容）。`FundamentalHealthEvaluator` の他定数と同様、Gate4実装時点では
     * 叩き台で、トライアル運用実績でのキャリブレーション対象
     * （accuracy-improvement-backlog.md）。10.0 ちょうどは「以上」で passed 側。
     */
    public const MIN_OPERATING_MARGIN = 10.0;

    /**
     * Returns 'passed' / 'unavailable' / 'failed' (see
     * tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php for the
     * rationale behind this 3-way string return, including the growth-rate
     * OR-condition added by the 2026-08-25 CR and the operating_margin 4th
     * criterion added by CHG-0012 / ADR-0011).
     */
    public function evaluate(?float $equityRatio, ?float $roe, ?float $revenueGrowth, ?float $operatingIncomeGrowth, ?float $operatingMargin): string
    {
        if ($equityRatio === null || $roe === null) {
            return 'unavailable';
        }

        // equity_ratio/roeのいずれかが基準未満であれば、成長率・営業利益率
        // データの有無に関わらず即座にfailedとする（/review 修正1: 成長率
        // データが両方未取得なだけでunavailableが優先され、財務的に不健全な
        // 銘柄がunavailable扱いになってしまうバグの再発防止。ADR-0011 D6 で
        // この不変条件を営業利益率にも適用する）。
        if ($equityRatio < self::MIN_EQUITY_RATIO || $roe < self::MIN_ROE) {
            return 'failed';
        }

        // 営業利益率（ADR-0011 D6）: 実測値があり基準割れのときのみ failed、
        // null は成長率と同じく unavailable。営業利益率チェックは成長率 OR
        // 判定より先に評価する（判定順序: 基準割れ → データ欠損 → 成長率）。
        if ($operatingMargin !== null && $operatingMargin < self::MIN_OPERATING_MARGIN) {
            return 'failed';
        }

        if ($operatingMargin === null) {
            return 'unavailable';
        }

        if ($revenueGrowth === null && $operatingIncomeGrowth === null) {
            return 'unavailable';
        }

        $growthPositive = ($revenueGrowth !== null && $revenueGrowth > self::MIN_GROWTH_RATE)
            || ($operatingIncomeGrowth !== null && $operatingIncomeGrowth > self::MIN_GROWTH_RATE);

        return $growthPositive ? 'passed' : 'failed';
    }
}
