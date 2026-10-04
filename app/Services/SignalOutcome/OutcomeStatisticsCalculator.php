<?php

namespace App\Services\SignalOutcome;

use InvalidArgumentException;

/**
 * UC-014 / ADR-0017 D7: summary statistics of matured excess returns (%).
 * t = mean / (s / sqrt(n)) with the sample standard deviation (n-1).
 * Hit: buy-side sources need > 0, take_profit needs < 0; exactly 0 is a miss.
 */
final class OutcomeStatisticsCalculator
{
    private const BUY_SOURCES = ['buy', 'watchlist_buy'];

    private const TAKE_PROFIT_SOURCE = 'take_profit';

    /**
     * @param  list<float>  $excessReturns
     * @return array{matured_count: int, mean: ?float, median: ?float, t_value: ?float, hit_rate: ?float}
     */
    public function calculate(array $excessReturns, string $source): array
    {
        $isBuy = in_array($source, self::BUY_SOURCES, true);

        if (! $isBuy && $source !== self::TAKE_PROFIT_SOURCE) {
            throw new InvalidArgumentException("Unknown source: {$source}");
        }

        $values = array_values($excessReturns);
        $n = count($values);

        if ($n === 0) {
            return [
                'matured_count' => 0,
                'mean' => null,
                'median' => null,
                't_value' => null,
                'hit_rate' => null,
            ];
        }

        $mean = array_sum($values) / $n;
        $hits = count(array_filter($values, fn (float $v) => $isBuy ? $v > 0 : $v < 0));

        return [
            'matured_count' => $n,
            'mean' => $mean,
            'median' => $this->median($values),
            't_value' => $this->tValue($values, $mean),
            'hit_rate' => $hits / $n * 100,
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @param  list<float>  $values
     */
    private function tValue(array $values, float $mean): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $variance = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / ($n - 1);
        $sd = sqrt($variance);

        if ($sd == 0.0) {
            return null;
        }

        return $mean / ($sd / sqrt($n));
    }
}
