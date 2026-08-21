<?php

namespace App\Services\Analysis;

/**
 * Calculates weekly technical indicators (RSI, MACD, moving averages,
 * Bollinger Bands, volume averages, 52-week high/low, relative strength)
 * from a chronologically ascending (oldest-first) weekly price history.
 *
 * Pure calculation logic only — no DB/HTTP dependency
 * (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md).
 */
final class TechnicalIndicatorCalculator
{
    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory  Ascending (oldest-first) weekly price history.
     * @param  float|null  $marketReturn13w  Benchmark (market) 13-week return in percent.
     * @param  float|null  $sectorReturn13w  Benchmark (sector) 13-week return in percent.
     * @return array{rsi: float|null, macd: float|null, macd_signal: float|null, ma20: float|null, ma75: float|null, bb_upper: float|null, bb_lower: float|null, volume: int|null, volume_ma20: float|null, week52_high: float|null, week52_low: float|null, relative_strength_vs_market: float|null, relative_strength_vs_sector: float|null}
     */
    public function calculate(array $priceHistory, ?float $marketReturn13w = null, ?float $sectorReturn13w = null): array
    {
        $count = count($priceHistory);
        $closes = array_map(static fn (array $row): float => (float) $row['close'], $priceHistory);
        $volumes = array_map(static fn (array $row): int => (int) $row['volume'], $priceHistory);

        [$bbUpper, $bbLower] = $this->calculateBollingerBands($closes, $count);

        return [
            'rsi' => $this->calculateRsi($closes, $count),
            'macd' => $this->calculateMacd($closes, $count),
            'macd_signal' => $this->calculateMacdSignal($closes, $count),
            'ma20' => $this->simpleMovingAverage($closes, $count, 20),
            'ma75' => $this->simpleMovingAverage($closes, $count, 75),
            'bb_upper' => $bbUpper,
            'bb_lower' => $bbLower,
            'volume' => $count >= 1 ? $volumes[$count - 1] : null,
            'volume_ma20' => $this->simpleMovingAverage($volumes, $count, 20),
            'week52_high' => $count >= 52 ? max(array_slice($closes, -52)) : null,
            'week52_low' => $count >= 52 ? min(array_slice($closes, -52)) : null,
            'relative_strength_vs_market' => $this->calculateRelativeStrength($closes, $count, $marketReturn13w),
            'relative_strength_vs_sector' => $this->calculateRelativeStrength($closes, $count, $sectorReturn13w),
        ];
    }

    /**
     * Simple average of the last $period values. Works for both close
     * prices (float) and volumes (int) — the ?float return type coerces
     * an evenly-divisible int sum/period result to float.
     *
     * @param  array<int, float|int>  $values
     */
    private function simpleMovingAverage(array $values, int $count, int $period): ?float
    {
        if ($count < $period) {
            return null;
        }

        return array_sum(array_slice($values, -$period)) / $period;
    }

    /**
     * @param  array<int, float>  $closes
     * @return array{0: float|null, 1: float|null} [$bbUpper, $bbLower]
     */
    private function calculateBollingerBands(array $closes, int $count): array
    {
        if ($count < 20) {
            return [null, null];
        }

        $window = array_slice($closes, -20);
        $mean = array_sum($window) / 20;
        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $window)) / (20 - 1);
        $stdev = sqrt($variance);

        return [$mean + 2 * $stdev, $mean - 2 * $stdev];
    }

    /**
     * RSI(14週), simple-average-based RS formula (not Wilder's smoothing).
     * avg_loss = 0 returns RSI = 100 (division-by-zero guard).
     *
     * @param  array<int, float>  $closes
     */
    private function calculateRsi(array $closes, int $count): ?float
    {
        if ($count < 15) {
            return null;
        }

        $window = array_slice($closes, -15);
        $gainTotal = 0.0;
        $lossTotal = 0.0;

        for ($i = 1; $i < 15; $i++) {
            $diff = $window[$i] - $window[$i - 1];

            if ($diff > 0) {
                $gainTotal += $diff;
            } else {
                $lossTotal += abs($diff);
            }
        }

        $avgLoss = $lossTotal / 14;

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $avgGain = $gainTotal / 14;
        $rs = $avgGain / $avgLoss;

        return 100 - 100 / (1 + $rs);
    }

    /**
     * @param  array<int, float>  $closes
     */
    private function calculateMacd(array $closes, int $count): ?float
    {
        if ($count < 26) {
            return null;
        }

        return $this->ema($closes, 12) - $this->ema($closes, 26);
    }

    /**
     * Signal line = 9-week EMA of the MACD line (the sequence of MACD
     * values recomputed at each point once at least 26 closes are
     * available).
     *
     * @param  array<int, float>  $closes
     */
    private function calculateMacdSignal(array $closes, int $count): ?float
    {
        if ($count < 35) {
            return null;
        }

        $macdSeries = [];

        for ($i = 25; $i < $count; $i++) {
            $subset = array_slice($closes, 0, $i + 1);
            $macdSeries[] = $this->ema($subset, 12) - $this->ema($subset, 26);
        }

        return $this->ema($macdSeries, 9);
    }

    /**
     * EMA seeded with the simple moving average of the first $period
     * values, then recursed with alpha = 2 / (period + 1).
     *
     * @param  array<int, float>  $values
     */
    private function ema(array $values, int $period): ?float
    {
        $count = count($values);

        if ($count < $period) {
            return null;
        }

        $alpha = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;

        for ($i = $period; $i < $count; $i++) {
            $ema = $alpha * $values[$i] + (1 - $alpha) * $ema;
        }

        return $ema;
    }

    /**
     * Stock 13-week return (%) minus the given benchmark 13-week return (%).
     * Returns null when there is insufficient history or no benchmark value.
     *
     * @param  array<int, float>  $closes
     */
    private function calculateRelativeStrength(array $closes, int $count, ?float $benchmarkReturn13w): ?float
    {
        if ($count < 14 || $benchmarkReturn13w === null) {
            return null;
        }

        $current = $closes[$count - 1];
        $past = $closes[$count - 14];
        $stockReturn13w = (($current - $past) / $past) * 100;

        return $stockReturn13w - $benchmarkReturn13w;
    }
}
