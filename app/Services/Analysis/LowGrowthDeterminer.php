<?php

namespace App\Services\Analysis;

/**
 * Shared "is this stock's growth rate low?" judgment used by both
 * SignalDeterminationService (sell-side PEG exclusion) and
 * BuySignalDeterminationService (buy-side PEG exclusion + PER/dividend-yield
 * substitution), per CHG-0017 / ADR-0015 D3.
 *
 * Extracted from two verbatim copies (2026-09-19 `/review`) to avoid the
 * threshold-drift risk this project has already been burned by once
 * (CHG-0005: duplicate signal thresholds in two classes drifting apart).
 * LOW_GROWTH_THRESHOLD is a provisional value pending real-data
 * calibration (docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md
 * D3); keeping one copy means recalibration only has one place to change.
 *
 * Pure calculation logic only — no DB/HTTP dependency.
 */
final class LowGrowthDeterminer
{
    public const LOW_GROWTH_THRESHOLD = 5.0;

    /**
     * Growth rate is the higher of revenueGrowth/operatingIncomeGrowth,
     * reusing SignalCriteriaEvaluator::higherGrowthRate() (the same "higher
     * of the two" computation the 判定チェックリスト already uses) instead
     * of a second copy — this class's own docblock names CHG-0005-style
     * threshold drift as exactly what duplicating this logic risks
     * (/review 3回目の指摘、2026-09-21).
     *
     * Returns false (not low) when both are null — the absence of growth
     * evidence is not itself evidence of low growth, so the existing
     * PEG-based judgment is left in place rather than assuming the worst.
     */
    public function isLowGrowth(?float $revenueGrowth, ?float $operatingIncomeGrowth): bool
    {
        $growth = SignalCriteriaEvaluator::higherGrowthRate($revenueGrowth, $operatingIncomeGrowth);

        if ($growth === null) {
            return false;
        }

        return $growth <= self::LOW_GROWTH_THRESHOLD;
    }
}
