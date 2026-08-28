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
            'target_gain_rate_threshold' => 20.0,
            'first_tier_price_multiplier' => 1.20,
            'second_tier_price_multiplier' => 1.35,
        ];
    }
}
