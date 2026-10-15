<?php

namespace App\Services\TradeReview;

use App\Services\TradeReview\Support\PeriodPerformance;
use App\Services\TradeReview\Support\PortfolioWeek;
use Illuminate\Support\Carbon;

/**
 * The time-weighted return of the stock part over a period and its
 * 20% / 25% judgement, plus the buy-and-hold comparison (UC-018 基本フロー
 * 1・2, Gate 2 draft 決定1). Works on the weeks of WeeklyPortfolioBuilder,
 * so one rebuild serves every market and period.
 */
class StockPerformanceCalculator
{
    private const TARGET = 0.20;

    private const UPPER_TARGET = 0.25;

    /** Above this share of the value left out in any week, the period is not judged. */
    private const MAX_EXCLUDED_RATIO = 0.05;

    /**
     * @param  array<string, PortfolioWeek>  $weeks  keyed by the Monday of the week
     * @param  array<int, string>  $markets  holding_id => 'jp' | 'us'
     * @param  'jp'|'us'|null  $market
     */
    public function calculate(array $weeks, array $markets, ?string $market, string $baseWeek, string $endWeek, int $judgeWeeks = 52): PeriodPerformance
    {
        $inMarket = fn (int $id): bool => $market === null || ($markets[$id] ?? null) === $market;

        $growth = 1.0;
        $used = 0;
        $skipped = 0;
        $periodWeeks = 0;
        $maxExcluded = isset($weeks[$baseWeek]) ? $this->excludedRatio($weeks[$baseWeek], $inMarket) : 0.0;
        $previous = $weeks[$baseWeek] ?? null;

        for ($week = $this->next($baseWeek); $week <= $endWeek; $week = $this->next($week)) {
            $current = $weeks[$week] ?? null;
            $periodWeeks++;

            if ($current === null || $previous === null) {
                $skipped++;
                $previous = $current;

                continue;
            }

            $maxExcluded = max($maxExcluded, $this->excludedRatio($current, $inMarket));
            $return = $this->weeklyReturn($previous, $current, $inMarket);

            if ($return === null) {
                $skipped++;
            } else {
                $growth *= 1 + $return;
                $used++;
            }

            $previous = $current;
        }

        $returnRate = $used > 0 ? $growth - 1 : null;
        $buyHold = isset($weeks[$baseWeek], $weeks[$endWeek]) ? $this->buyHoldRate($weeks[$baseWeek], $weeks[$endWeek], $inMarket) : null;
        $days = Carbon::parse($baseWeek)->diffInDays(Carbon::parse($endWeek));

        return new PeriodPerformance(
            baseWeek: $baseWeek,
            endWeek: $endWeek,
            returnRate: $returnRate,
            buyHoldRate: $buyHold,
            diffPoints: $returnRate !== null && $buyHold !== null ? ($returnRate - $buyHold) * 100 : null,
            verdict: $this->verdict($returnRate, $periodWeeks, $judgeWeeks, $maxExcluded),
            periodTarget20: (1 + self::TARGET) ** ($days / 365) - 1,
            periodTarget25: (1 + self::UPPER_TARGET) ** ($days / 365) - 1,
            weeksUsed: $used,
            weeksSkipped: $skipped,
            maxExcludedRatio: $maxExcluded,
        );
    }

    /**
     * Modified Dietz over the holdings valued in both weeks:
     * (V_t − V_{t−1} − F_t) / (V_{t−1} + 0.5 F_t); null when the denominator is <= 0.
     *
     * @param  callable(int): bool  $inMarket
     */
    private function weeklyReturn(PortfolioWeek $previous, PortfolioWeek $current, callable $inMarket): ?float
    {
        $ids = array_keys($previous->holdingValuesJpy + $current->holdingValuesJpy + $current->holdingFlowsJpy);
        $before = 0.0;
        $after = 0.0;
        $flow = 0.0;

        foreach ($ids as $id) {
            if (! $inMarket($id) || isset($previous->excluded[$id]) || isset($current->excluded[$id])) {
                continue;
            }

            $before += $previous->holdingValuesJpy[$id] ?? 0.0;
            $after += $current->holdingValuesJpy[$id] ?? 0.0;
            $flow += $current->holdingFlowsJpy[$id] ?? 0.0;
        }

        $denominator = $before + 0.5 * $flow;

        return $denominator > 0 ? ($after - $before - $flow) / $denominator : null;
    }

    /**
     * @param  callable(int): bool  $inMarket
     */
    private function excludedRatio(PortfolioWeek $week, callable $inMarket): float
    {
        $value = 0.0;
        $estimate = 0.0;

        foreach ($week->holdingValuesJpy as $id => $amount) {
            $value += $inMarket($id) ? $amount : 0.0;
        }

        foreach ($week->excludedEstimatesJpy as $id => $amount) {
            $estimate += $inMarket($id) ? $amount : 0.0;
        }

        return $value + $estimate > 0 ? $estimate / ($value + $estimate) : 0.0;
    }

    /**
     * The shares held at the end of the base week, valued at the end week's
     * prices against the base week's, over the holdings priced in both.
     *
     * @param  callable(int): bool  $inMarket
     */
    private function buyHoldRate(PortfolioWeek $base, PortfolioWeek $end, callable $inMarket): ?float
    {
        $before = 0.0;
        $after = 0.0;

        foreach ($base->quantities as $id => $shares) {
            if (! $inMarket($id) || ! isset($base->pricesJpy[$id], $end->pricesJpy[$id])) {
                continue;
            }

            $before += $shares * $base->pricesJpy[$id];
            $after += $shares * $end->pricesJpy[$id];
        }

        return $before > 0 ? $after / $before - 1 : null;
    }

    private function verdict(?float $returnRate, int $periodWeeks, int $judgeWeeks, float $maxExcluded): string
    {
        if ($returnRate === null) {
            return 'unavailable';
        }

        if ($periodWeeks < $judgeWeeks) {
            return 'reference';
        }

        if ($maxExcluded > self::MAX_EXCLUDED_RATIO) {
            return 'pending';
        }

        // Rounded so 120 / 100 − 1 counts as exactly 20% despite float error.
        $rate = round($returnRate, 10);

        return $rate >= self::UPPER_TARGET ? 'met_25' : ($rate >= self::TARGET ? 'met_20' : 'below_20');
    }

    private function next(string $week): string
    {
        return Carbon::parse($week)->addWeek()->toDateString();
    }
}
