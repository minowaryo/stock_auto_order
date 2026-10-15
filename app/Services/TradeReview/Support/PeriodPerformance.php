<?php

namespace App\Services\TradeReview\Support;

/**
 * The stock-part return of one period and its judgement (UC-018 基本フロー1・2,
 * Gate 2 draft 決定1). Rates are fractions (0.2 = 20%); diffPoints is in
 * percentage points.
 */
final class PeriodPerformance
{
    /**
     * @param  'below_20'|'met_20'|'met_25'|'pending'|'reference'|'unavailable'  $verdict
     */
    public function __construct(
        public readonly string $baseWeek,
        public readonly string $endWeek,
        public readonly ?float $returnRate,
        public readonly ?float $buyHoldRate,
        public readonly ?float $diffPoints,
        public readonly string $verdict,
        public readonly float $periodTarget20,
        public readonly float $periodTarget25,
        public readonly int $weeksUsed,
        public readonly int $weeksSkipped,
        public readonly float $maxExcludedRatio,
    ) {}
}
