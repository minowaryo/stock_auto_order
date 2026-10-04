<?php

namespace App\Support;

/**
 * CHG-0034 (ADR-0023 D5/D6): display mapping only (tier -> label / badge variant,
 * no-verdict reason -> message). The tier decision itself lives in the
 * Analysis services.
 */
final class ValuationDisplay
{
    private const VALUATION = [
        'strong_cheap' => ['強い割安', 'success-strong'],
        'cheap' => ['割安', 'success'],
        'fair' => ['並み', 'neutral'],
        'expensive' => ['割高', 'warning'],
        'strong_expensive' => ['強い割高', 'warning-strong'],
    ];

    private const TONE = [
        'strong_good' => ['強い良好', 'success-strong'],
        'good' => ['良好', 'success'],
        'below' => ['基準未満', 'warning'],
        'strong_below' => ['大きく下回る', 'warning-strong'],
    ];

    private const REASONS = [
        'invalid_value' => '値が0以下のため判定なし',
        'sector_unclassified' => '業種が未分類のため判定なし',
        'no_benchmark' => 'この業種は基準表にないため判定なし',
        'confidence_none' => 'この業種は基準の対象外のため判定なし',
    ];

    /**
     * @return array{label: string, variant: string}|null
     */
    public static function valuation(?string $tier): ?array
    {
        return self::pick(self::VALUATION, $tier);
    }

    /**
     * @return array{label: string, variant: string}|null
     */
    public static function tone(?string $tone): ?array
    {
        return self::pick(self::TONE, $tone);
    }

    public static function reason(?string $reason): ?string
    {
        return self::REASONS[$reason] ?? null;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $map
     * @return array{label: string, variant: string}|null
     */
    private static function pick(array $map, ?string $key): ?array
    {
        if ($key === null || ! isset($map[$key])) {
            return null;
        }

        return ['label' => $map[$key][0], 'variant' => $map[$key][1]];
    }
}
