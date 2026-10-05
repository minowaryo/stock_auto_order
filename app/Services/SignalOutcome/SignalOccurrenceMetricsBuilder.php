<?php

namespace App\Services\SignalOutcome;

use App\Services\Analysis\ValuationBenchmarkJudge;
use App\Services\MarketData\WeekDateNormalizer;
use Throwable;

/**
 * Builds the signal_occurrences inputs (observed_week / metrics) from
 * values the analysis actions have already computed (UC-014, ADR-0017 D3).
 * Shared by FetchExternalMarketDataAction and
 * RefreshWatchlistMarketDataAction so the metrics shape never diverges.
 */
class SignalOccurrenceMetricsBuilder
{
    public function __construct(
        private readonly WeekDateNormalizer $weekDateNormalizer,
        private readonly ValuationBenchmarkJudge $valuationBenchmarkJudge,
    ) {}

    /**
     * Week start of the last bar of the price history used for
     * determination; null when it cannot be resolved (nothing is recorded).
     *
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
     */
    public function observedWeek(array $priceHistory): ?string
    {
        if ($priceHistory === []) {
            return null;
        }

        try {
            return $this->weekDateNormalizer->weekStart($priceHistory[count($priceHistory) - 1]['date']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The PER judgement basis is stored on signal_occurrences only; the
     * weekly indicator observations (UC-018) keep their original key set.
     *
     * @param  array<string, mixed>  $metrics  build() result
     * @return array<string, mixed>
     */
    public function withoutPerBasis(array $metrics): array
    {
        return array_diff_key($metrics, array_flip(['per_basis', 'per_benchmark', 'per_factor', 'per_valuation_tier']));
    }

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
     * @param  array<string, mixed>  $technical  TechnicalIndicatorCalculator::calculate() result
     * @param  array<string, mixed>  $fundamental  fundamental mapper result (+ avg growth when available)
     * @param  ?string  $market  when given, the PER judgement basis is also stored (ADR-0026 D5)
     * @return array<string, mixed>
     */
    public function build(array $priceHistory, array $technical, array $fundamental, ?string $market = null, ?string $sectorName = null): array
    {
        $metrics = [
            'close' => $priceHistory !== [] ? (float) $priceHistory[count($priceHistory) - 1]['close'] : null,
            'rsi' => $technical['rsi'] ?? null,
            'week52_high' => $technical['week52_high'] ?? null,
            'relative_strength_vs_market' => $technical['relative_strength_vs_market'] ?? null,
            'relative_strength_vs_sector' => $technical['relative_strength_vs_sector'] ?? null,
            'ma75_trend_rising' => $technical['ma75_trend_rising'] ?? null,
            'per' => $fundamental['per'] ?? null,
            'pbr' => $fundamental['pbr'] ?? null,
            'peg_ratio' => $fundamental['peg_ratio'] ?? null,
            'roe' => $fundamental['roe'] ?? null,
            'equity_ratio' => $fundamental['equity_ratio'] ?? null,
            'operating_margin' => $fundamental['operating_margin'] ?? null,
            'revenue_growth' => $fundamental['revenue_growth'] ?? null,
            'operating_income_growth' => $fundamental['operating_income_growth'] ?? null,
            'avg_revenue_growth' => $fundamental['avg_revenue_growth'] ?? null,
            'avg_operating_income_growth' => $fundamental['avg_operating_income_growth'] ?? null,
            'dividend_yield' => $fundamental['dividend_yield'] ?? null,
        ];

        if ($market === null) {
            return $metrics;
        }

        $per = isset($fundamental['per']) ? (float) $fundamental['per'] : null;
        $verdict = $this->valuationBenchmarkJudge->buyPerVerdict($per, $market, $sectorName);

        return [
            ...$metrics,
            'per_basis' => $verdict['basis'],
            'per_benchmark' => $verdict['benchmark'],
            'per_factor' => $verdict['factor'],
            'per_valuation_tier' => $this->valuationBenchmarkJudge->judge('per', $per, $market, $sectorName)['tier'],
        ];
    }
}
