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
     * Returns 'passed' / 'unavailable' / 'failed' (see
     * tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php for the
     * rationale behind this 3-way string return, including the growth-rate
     * OR-condition added by the 2026-08-25 CR).
     */
    public function evaluate(?float $equityRatio, ?float $roe, ?float $revenueGrowth, ?float $operatingIncomeGrowth): string
    {
        if ($equityRatio === null || $roe === null) {
            return 'unavailable';
        }

        // equity_ratio/roeのいずれかが基準未満であれば、成長率データの有無に
        // 関わらず即座にfailedとする（/review 修正1: 成長率データが両方
        // 未取得なだけでunavailableが優先され、財務的に不健全な銘柄が
        // unavailable扱いになってしまうバグの再発防止）。
        if ($equityRatio < self::MIN_EQUITY_RATIO || $roe < self::MIN_ROE) {
            return 'failed';
        }

        if ($revenueGrowth === null && $operatingIncomeGrowth === null) {
            return 'unavailable';
        }

        $growthPositive = ($revenueGrowth !== null && $revenueGrowth > self::MIN_GROWTH_RATE)
            || ($operatingIncomeGrowth !== null && $operatingIncomeGrowth > self::MIN_GROWTH_RATE);

        return $growthPositive ? 'passed' : 'failed';
    }
}
