<?php

namespace App\Support;

/**
 * UC-014「根拠値の表示」(CHG-0020 Cycle5): renders signal_occurrences.metrics
 * (keys from SignalOccurrenceMetricsBuilder) as 「ラベル: 値」 for the
 * シグナル検証 detail. Numbers → 2 decimals, booleans → はい／いいえ,
 * nulls omitted, unknown keys keep the raw key.
 */
final class SignalOccurrenceMetricLabels
{
    private const LABELS = [
        'close' => '終値',
        'rsi' => 'RSI',
        'week52_high' => '52週高値',
        'relative_strength_vs_market' => '相対強度（対市場）',
        'relative_strength_vs_sector' => '相対強度（対セクター）',
        'ma75_trend_rising' => '75日線上昇',
        'per' => 'PER',
        'pbr' => 'PBR',
        'peg_ratio' => 'PEGレシオ',
        'roe' => 'ROE',
        'equity_ratio' => '自己資本比率',
        'operating_margin' => '営業利益率',
        'revenue_growth' => '売上成長率',
        'operating_income_growth' => '営業利益成長率',
        'avg_revenue_growth' => '平均売上成長率',
        'avg_operating_income_growth' => '平均営業利益成長率',
        'dividend_yield' => '配当利回り',
        'per_basis' => 'PER判定の基準',
        'per_benchmark' => '業種の基準PER',
        'per_factor' => '基準比のしきい値',
        'per_valuation_tier' => '業種比較の段階',
    ];

    /**
     * @param  array<string, mixed>  $metrics
     * @return list<string> 「ラベル: 値」 in LABELS order (MySQL JSON reorders stored keys), unknown keys last, nulls omitted
     */
    public static function format(array $metrics): array
    {
        // Known keys take LABELS order; keys absent from LABELS are appended in input order.
        $ordered = array_replace(array_intersect_key(self::LABELS, $metrics), $metrics);
        $items = [];

        foreach ($ordered as $key => $value) {
            if ($value === null) {
                continue;
            }

            $items[] = (self::LABELS[$key] ?? $key).': '.self::display($key, $value);
        }

        return $items;
    }

    private static function display(string $key, mixed $value): string
    {
        return match ($key) {
            'per_basis' => match ($value) {
                'sector' => '業種比較',
                'fixed' => '固定（PER≦15）',
                default => self::value($value),
            },
            'per_factor' => (float) $value.'倍',
            'per_valuation_tier' => ValuationDisplay::valuation((string) $value)['label'] ?? self::value($value),
            default => self::value($value),
        };
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'はい' : 'いいえ',
            is_int($value), is_float($value) => number_format($value, 2),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
