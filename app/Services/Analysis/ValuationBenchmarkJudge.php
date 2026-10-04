<?php

namespace App\Services\Analysis;

/**
 * Judges PER/PBR against a sector benchmark (CHG-0034, ADR-0023 D3-D6,
 * ADR-0026 D3): 5-tier color judgement by actual value / benchmark ratio.
 *
 * Pure calculation logic only — no DB/HTTP/config dependency. The benchmark
 * table (same shape as config/valuation_benchmarks.php) is injected so the
 * class stays testable and the caller decides where the table comes from.
 */
final class ValuationBenchmarkJudge
{
    /**
     * 買い増し基準PERの係数 (ADR-0026 D3): 業種の基準PERの0.8倍。
     * 「割安」帯の上限（比率0.80）と同じ値で、画面の色と買い増し判定を揃える。
     */
    private const BUY_PER_RATIO = 0.8;

    /**
     * 信頼度lowの業種の買い増し基準PERの係数 (ADR-0026 D3, 2026-10-04改訂):
     * 基準の誤差を割安と誤判定しないよう、0.8より厳しくする。
     */
    private const BUY_PER_RATIO_LOW_CONFIDENCE = 0.7;

    /**
     * @param  array<string, array<string, mixed>>  $table  market ('jp'/'us') => ['as_of', 'source', 'per' => [sector => ['value', 'confidence']], 'pbr' => ...]
     */
    public function __construct(private readonly array $table) {}

    /**
     * @return array{tier: ?string, ratio: ?float, benchmark: ?float, confidence: ?string, unstable: bool, as_of: ?string, source: ?string, reason: ?string}
     */
    public function judge(string $metric, ?float $value, string $market, ?string $sectorName): array
    {
        $asOf = $this->table[$market]['as_of'] ?? null;
        $source = $this->table[$market]['source'] ?? null;

        $none = fn (string $reason, ?array $entry = null): array => [
            'tier' => null,
            'ratio' => null,
            'benchmark' => $entry === null ? null : round((float) $entry['value'], 1),
            'confidence' => $entry['confidence'] ?? null,
            'unstable' => false,
            'as_of' => $asOf,
            'source' => $source,
            'reason' => $reason,
        ];

        // Zero/negative PER or PBR (loss-making, negative equity) has no
        // meaningful ratio (ADR-0023 D6).
        if ($value === null || $value <= 0) {
            return $none('invalid_value');
        }

        if ($sectorName === null) {
            return $none('sector_unclassified');
        }

        $entry = $this->table[$market][$metric][$sectorName] ?? null;
        if ($entry === null) {
            return $none('no_benchmark');
        }

        $confidence = $entry['confidence'];
        if ($confidence === 'none') {
            return $none('confidence_none', $entry);
        }

        // Round the value to the displayed digit before comparing so the color
        // never contradicts the number on screen (ADR-0023 D3, same as
        // ADR-0021 D2). The benchmark is used as listed (PBR benchmarks carry
        // two decimals; rounding 0.74 to 0.7 would waste the precision).
        $benchmark = (float) $entry['value'];
        $ratio = $this->ratio($value, $benchmark);

        $tier = $this->tierFor($ratio);

        // Deep colors only for high-confidence benchmarks: with a shaky
        // benchmark, a "strong" tier would mistake benchmark error for an
        // outlier (ADR-0023 D4).
        if ($confidence !== 'high') {
            $tier = match ($tier) {
                'strong_cheap' => 'cheap',
                'strong_expensive' => 'expensive',
                default => $tier,
            };
        }

        return [
            'tier' => $tier,
            'ratio' => $ratio,
            'benchmark' => $benchmark,
            'confidence' => $confidence,
            'unstable' => $confidence === 'low',
            'as_of' => $asOf,
            'source' => $source,
            'reason' => null,
        ];
    }

    /**
     * Benchmark PER x 0.8 (high/medium) or x 0.7 (low) for the buy signal
     * (ADR-0026 D3), or null when the sector has no usable benchmark
     * (unclassified, not in table, none confidence).
     */
    public function buyPerThreshold(string $market, ?string $sectorName): ?float
    {
        $entry = $this->buyPerEntry($market, $sectorName);
        if ($entry === null) {
            return null;
        }

        return round((float) $entry['value'] * $this->buyPerFactor($entry['confidence']), 1);
    }

    /**
     * Buy-signal PER condition (ADR-0026 D3, 2026-10-04): ratio below the
     * confidence-dependent factor when the sector has a usable benchmark,
     * otherwise the fixed PER <= 15.0. A null / non-positive PER never meets.
     *
     * @return array{met: bool, basis: string, threshold: float, benchmark: ?float, factor: ?float}
     */
    public function buyPerVerdict(?float $per, string $market, ?string $sectorName): array
    {
        $entry = $this->buyPerEntry($market, $sectorName);
        $isPositive = $per !== null && $per > 0.0;

        if ($entry === null) {
            $threshold = BuySignalDeterminationService::PER_UNDERVALUED_THRESHOLD;

            return [
                'met' => $isPositive && $per <= $threshold,
                'basis' => 'fixed',
                'threshold' => $threshold,
                'benchmark' => null,
                'factor' => null,
            ];
        }

        $benchmark = (float) $entry['value'];
        $factor = $this->buyPerFactor($entry['confidence']);

        return [
            'met' => $isPositive && $this->ratio($per, $benchmark) < $factor,
            'basis' => 'sector',
            'threshold' => round($benchmark * $factor, 1),
            'benchmark' => $benchmark,
            'factor' => $factor,
        ];
    }

    /**
     * @return array{value: float|int, confidence: string}|null the PER benchmark entry usable for the buy signal
     */
    private function buyPerEntry(string $market, ?string $sectorName): ?array
    {
        if ($sectorName === null) {
            return null;
        }

        $entry = $this->table[$market]['per'][$sectorName] ?? null;
        if ($entry === null || $entry['confidence'] === 'none') {
            return null;
        }

        return $entry;
    }

    private function buyPerFactor(string $confidence): float
    {
        return $confidence === 'low' ? self::BUY_PER_RATIO_LOW_CONFIDENCE : self::BUY_PER_RATIO;
    }

    /**
     * Round the value to the displayed digit before comparing so the verdict
     * never contradicts the number on screen (ADR-0023 D3, same as
     * ADR-0021 D2). The benchmark is used as listed.
     */
    private function ratio(float $value, float $benchmark): float
    {
        return round(round($value, 1) / $benchmark, 2);
    }

    /**
     * Boundaries fall on the milder side (ratio exactly 0.50 is "cheap",
     * 2.00 is "expensive"), per ADR-0023 D3.
     */
    private function tierFor(float $ratio): string
    {
        return match (true) {
            $ratio < 0.50 => 'strong_cheap',
            $ratio < 0.80 => 'cheap',
            $ratio <= 1.25 => 'fair',
            $ratio <= 2.00 => 'expensive',
            default => 'strong_expensive',
        };
    }
}
