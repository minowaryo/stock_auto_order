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

        // Round to the displayed digit before comparing so the color never
        // contradicts the number on screen (ADR-0023 D3, same as ADR-0021 D2).
        $benchmark = round((float) $entry['value'], 1);
        $ratio = round(round($value, 1) / $benchmark, 2);

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
     * Benchmark PER x 0.8 for the buy signal (ADR-0026 D3), or null when the
     * sector has no usable benchmark (unclassified, not in table, low/none
     * confidence). Medium confidence is allowed here.
     */
    public function buyPerThreshold(string $market, ?string $sectorName): ?float
    {
        if ($sectorName === null) {
            return null;
        }

        $entry = $this->table[$market]['per'][$sectorName] ?? null;
        if ($entry === null || ! in_array($entry['confidence'], ['high', 'medium'], true)) {
            return null;
        }

        return round(round((float) $entry['value'], 1) * self::BUY_PER_RATIO, 1);
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
