<?php

namespace App\Services\Portfolio;

use App\Models\HoldingSnapshot;
use Illuminate\Support\Collection;

/**
 * Portfolio evaluation total (quantity × current_price, summed across
 * holdings), shared by UC-008 (NewCandidateFinder) and UC-010
 * (ShowBuySignalListAction) for their suggested_amount calculations.
 * Mutual funds are corrected by ÷10000 since their current_price is a
 * per-10,000-unit basis price (基準価額), unlike stock/ETF prices.
 */
class PortfolioEvaluationCalculator
{
    /**
     * @param  Collection<int, HoldingSnapshot>  $holdingSnapshots
     */
    public function total(Collection $holdingSnapshots): float
    {
        return (float) $holdingSnapshots->sum(function (HoldingSnapshot $holdingSnapshot) {
            $value = (float) $holdingSnapshot->quantity * (float) $holdingSnapshot->current_price;

            if ($holdingSnapshot->holding->instrument_type === 'mutual_fund') {
                $value /= 10000;
            }

            return $value;
        });
    }
}
