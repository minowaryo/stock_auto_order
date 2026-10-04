<?php

namespace App\Services\Analysis;

/**
 * Display-only color tone for fundamental health metrics (CHG-0034,
 * ADR-0023 D9): strong_good / good / below / strong_below.
 *
 * Never used for FundamentalHealthEvaluator, take-profit thresholds or buy
 * signals (ADR-0023 D7). Pure calculation logic only — no DB/HTTP/config
 * dependency.
 */
final class FundamentalMetricToneEvaluator
{
    /**
     * 強い良好のライン（市場別。null は「付けない」）。根拠は
     * valuation-benchmarks.md 7章。叩き台でキャリブレーション対象。
     */
    private const STRONG_GOOD = [
        'roe' => ['jp' => 15.0, 'us' => 25.0],
        'equity_ratio' => ['jp' => 70.0, 'us' => null],
        'operating_margin' => ['jp' => 20.0, 'us' => 30.0],
        'revenue_growth' => ['jp' => 15.0, 'us' => 25.0],
        'operating_income_growth' => ['jp' => 30.0, 'us' => 30.0],
    ];

    /**
     * 基準未満（黄）の下限。これ未満は「大きく下回る」。日本株・米国株共通で、
     * 外部の根拠がない叩き台。
     */
    private const STRONG_BELOW = [
        'roe' => 0.0,
        'equity_ratio' => 20.0,
        'operating_margin' => 0.0,
        'revenue_growth' => -10.0,
        'operating_income_growth' => -20.0,
    ];

    /**
     * 業態上意味を持たない指標を判定から外す業種（ADR-0023 D9）。
     */
    private const EXCLUDED_SECTORS = [
        'equity_ratio' => ['銀行', '金融（除く銀行）', '不動産'],
        'operating_margin' => ['銀行', '金融（除く銀行）'],
    ];

    public function tone(string $metric, ?float $value, string $market, ?string $sectorName): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($sectorName !== null && in_array($sectorName, self::EXCLUDED_SECTORS[$metric] ?? [], true)) {
            return null;
        }

        // Judge on the displayed digit so the color never contradicts the
        // number on screen (ADR-0023 D9).
        $value = round($value, 1);

        $strongGood = self::STRONG_GOOD[$metric][$market] ?? null;
        if ($strongGood !== null && $value >= $strongGood) {
            return 'strong_good';
        }

        if ($this->isGood($metric, $value)) {
            return 'good';
        }

        return $value >= self::STRONG_BELOW[$metric] ? 'below' : 'strong_below';
    }

    /**
     * The good lower bound reuses FundamentalHealthEvaluator's constants so
     * the screen color and the health judgement never disagree. Growth rates
     * are "> MIN_GROWTH_RATE" there (strictly positive), the others "<=".
     */
    private function isGood(string $metric, float $value): bool
    {
        return match ($metric) {
            'roe' => $value >= FundamentalHealthEvaluator::MIN_ROE,
            'equity_ratio' => $value >= FundamentalHealthEvaluator::MIN_EQUITY_RATIO,
            'operating_margin' => $value >= FundamentalHealthEvaluator::MIN_OPERATING_MARGIN,
            default => $value > FundamentalHealthEvaluator::MIN_GROWTH_RATE,
        };
    }
}
