<?php

namespace App\Services\TradeReview\Support;

/**
 * The stock part of the portfolio in one week (UC-018, Gate 2 draft 決定1):
 * value V_t and money flow F_t in JPY over the holdings that could be
 * valued, and the holdings left out with an estimate of their value.
 */
final class PortfolioWeek
{
    /**
     * @param  array<int, float>  $quantities  holding_id => split-adjusted shares at the end of the week (> 0)
     * @param  array<int, string>  $excluded  holding_id => no_price / no_fx / split_pending / split_incomplete
     * @param  array<int, float>  $holdingValuesJpy  holding_id => value JPY (valued holdings only)
     * @param  array<int, float>  $holdingFlowsJpy  holding_id => money flow JPY (valued holdings only)
     * @param  array<int, float>  $excludedEstimatesJpy  holding_id => estimated value JPY (excluded holdings)
     * @param  array<int, float>  $pricesJpy  holding_id => JPY per split-adjusted share, for every traded holding with a close (and USD/JPY) that week
     */
    public function __construct(
        public readonly string $week,
        public readonly float $valueJpy,
        public readonly float $flowJpy,
        public readonly array $quantities,
        public readonly array $excluded,
        public readonly float $excludedEstimateJpy,
        public readonly array $holdingValuesJpy = [],
        public readonly array $holdingFlowsJpy = [],
        public readonly array $excludedEstimatesJpy = [],
        public readonly array $pricesJpy = [],
    ) {}
}
