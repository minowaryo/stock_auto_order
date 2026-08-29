<?php

namespace App\Services\Analysis;

/**
 * Determines which take-profit threshold band ("normal" / "high_water_mark")
 * applies to a holding (UC-004/UC-009, CHG-0006). See
 * tests/Unit/Services/Analysis/TakeProfitThresholdEvaluatorTest.php for the
 * design rationale behind this class's shape.
 *
 * Pure calculation logic only — no DB/HTTP dependency.
 */
final class TakeProfitThresholdEvaluator
{
    /**
     * The lowest `target_gain_rate_threshold` this class can ever return
     * (currently the "normal" mode's own threshold). Callers that need a
     * cheap SQL-level pre-filter before per-holding evaluation (e.g.
     * ShowSignalListAction) should filter against this constant rather than
     * a bare literal, so the two stay in sync if a future CR changes either
     * mode's threshold.
     */
    public const MIN_POSSIBLE_GAIN_RATE_THRESHOLD = 20.0;

    public function __construct(private readonly FundamentalHealthEvaluator $evaluator) {}

    /**
     * @return array{mode: string, target_gain_rate_threshold: float, first_tier_price_multiplier: float, second_tier_price_multiplier: float}
     */
    public function evaluate(int $signalCount, ?float $equityRatio, ?float $roe, ?float $revenueGrowth, ?float $operatingIncomeGrowth): array
    {
        if ($signalCount === 0 && $this->evaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth) === 'passed') {
            return [
                'mode' => 'high_water_mark',
                'target_gain_rate_threshold' => 150.0,
                'first_tier_price_multiplier' => 2.00,
                'second_tier_price_multiplier' => 2.50,
            ];
        }

        return [
            'mode' => 'normal',
            'target_gain_rate_threshold' => self::MIN_POSSIBLE_GAIN_RATE_THRESHOLD,
            'first_tier_price_multiplier' => 1.20,
            'second_tier_price_multiplier' => 1.35,
        ];
    }
}
