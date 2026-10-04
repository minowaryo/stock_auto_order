<?php

namespace App\Services\Analysis;

/**
 * Builds the CHG-0034 display inputs (per_verdict / pbr_verdict /
 * metric_tones) that SignalCriteriaEvaluator reads from its $metrics, so the
 * five checklist Actions share one assembly point (ADR-0023 D9, ADR-0026).
 *
 * Display-only; individual stocks only.
 */
final class CriteriaValuationMetricsBuilder
{
    private const TONE_METRICS = ['roe', 'equity_ratio', 'operating_margin', 'revenue_growth', 'operating_income_growth'];

    public function __construct(
        private readonly ValuationBenchmarkJudge $valuationJudge,
        private readonly FundamentalMetricToneEvaluator $toneEvaluator,
    ) {}

    /**
     * @param  object|null  $fundamentalIndicator  FundamentalIndicator model (decimal columns may be strings)
     * @return array{per_verdict: array<string, mixed>, pbr_verdict: array<string, mixed>, metric_tones: array<string, string|null>}
     */
    public function build(string $market, ?string $sectorName, ?object $fundamentalIndicator): array
    {
        $float = fn (string $column): ?float => $fundamentalIndicator?->{$column} === null ? null : (float) $fundamentalIndicator->{$column};

        $tones = [];
        foreach (self::TONE_METRICS as $metric) {
            $tones[$metric] = $this->toneEvaluator->tone($metric, $float($metric), $market, $sectorName);
        }

        return [
            'per_verdict' => $this->valuationJudge->judge('per', $float('per'), $market, $sectorName),
            'pbr_verdict' => $this->valuationJudge->judge('pbr', $float('pbr'), $market, $sectorName),
            'metric_tones' => $tones,
        ];
    }
}
