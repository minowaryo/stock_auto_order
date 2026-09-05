<?php

namespace App\Services\Analysis;

/**
 * Builds the 判定チェックリスト (criteria checklist) shown on the 売買シグナル
 * screen (UC-004 利確検討 / UC-010 買い増し候補, CHG-0007): for each holding
 * row, pairs the threshold already used by SignalDeterminationService /
 * BuySignalDeterminationService / FundamentalHealthEvaluator with the
 * holding's own measured value, and classifies how close it is
 * ('met' / 'near' / 'unmet' / 'unavailable').
 *
 * Display-only: does not replicate or influence the actual signal
 * persistence/gating logic in the *DeterminationService classes — it reads
 * their threshold constants to stay in sync (docs/architecture/data-model.md
 * "判定チェックリストの『あと一歩（near）』バッファ").
 *
 * Pure calculation logic only — no DB/HTTP dependency.
 */
final class SignalCriteriaEvaluator
{
    /**
     * "あと一歩 (near)" buffer, as a fraction of |threshold| (Gate 3,
     * data-model.md). A threshold of exactly 0 yields a buffer of 0, which
     * collapses the item to a 2-value met/unmet classification (by design —
     * a ratio-based buffer is not meaningful around a zero threshold).
     */
    private const NEAR_BUFFER_RATE = 0.2;

    private const UNAVAILABLE_LABEL = '—';

    /**
     * @param  array<string, float|null>  $metrics
     * @return array{
     *   technical: list<array{label: string, threshold_label: string, value_label: string, status: string}>,
     *   fundamental: list<array{label: string, threshold_label: string, value_label: string, status: string}>,
     *   summary: array{technical: array{met: int, near: int, total: int}, fundamental: array{met: int, near: int, total: int}},
     * }
     */
    public function evaluateTakeProfit(array $metrics): array
    {
        $gainLineThreshold = $metrics['gain_line_threshold'] ?? 20.0;

        $technical = [
            $this->row(
                '含み益率',
                sprintf('≥+%d%%', (int) round($gainLineThreshold)),
                $metrics['unrealized_gain_rate'] ?? null,
                $gainLineThreshold,
                'gte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'RSI',
                sprintf('≥%d', (int) SignalDeterminationService::RSI_REVERSAL_THRESHOLD),
                $metrics['rsi'] ?? null,
                SignalDeterminationService::RSI_REVERSAL_THRESHOLD,
                'gte',
                fn (float $v) => number_format($v, 1),
            ),
            $this->row(
                '52週高値からの下落率',
                '≤-10%',
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['week52_high'] ?? null),
                (SignalDeterminationService::WEEK52_HIGH_PULLBACK_RATE - 1) * 100,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'ボリンジャー上限乖離',
                '≥0%',
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['bb_upper'] ?? null),
                // SignalDeterminationService::determineBollingerOverheat()は
                // 「終値≧bb_upper」（乖離率換算で0%以上）を基準とするため0.0。
                0.0,
                'gte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'MACD-シグナル線',
                '<0',
                $this->macdDiff($metrics['macd'] ?? null, $metrics['macd_signal'] ?? null),
                SignalDeterminationService::MACD_CROSS_THRESHOLD,
                'lt',
                fn (float $v) => number_format($v, 2),
            ),
            $this->row(
                'PEGレシオ',
                sprintf('≥%s', number_format(SignalDeterminationService::PEG_OVERVALUED_THRESHOLD, 1)),
                $metrics['peg_ratio'] ?? null,
                SignalDeterminationService::PEG_OVERVALUED_THRESHOLD,
                'gte',
                fn (float $v) => number_format($v, 2),
            ),
            $this->row(
                '相対力(対市場)',
                '<0',
                $metrics['relative_strength_vs_market'] ?? null,
                SignalDeterminationService::RELATIVE_STRENGTH_WEAKENING_THRESHOLD,
                'lt',
                fn (float $v) => sprintf('%+.1f', $v),
            ),
        ];

        $fundamental = $this->fundamentalRows($metrics);

        return [
            'technical' => $technical,
            'fundamental' => $fundamental,
            'summary' => [
                'technical' => $this->summarize($technical),
                'fundamental' => $this->summarize($fundamental),
            ],
        ];
    }

    /**
     * @param  array<string, float|null>  $metrics
     * @return array{
     *   technical: list<array{label: string, threshold_label: string, value_label: string, status: string}>,
     *   fundamental: list<array{label: string, threshold_label: string, value_label: string, status: string}>,
     *   summary: array{technical: array{met: int, near: int, total: int}, fundamental: array{met: int, near: int, total: int}},
     * }
     */
    public function evaluateBuy(array $metrics): array
    {
        $technical = [
            $this->row(
                'RSI',
                sprintf('≤%d', (int) BuySignalDeterminationService::RSI_OVERSOLD_THRESHOLD),
                $metrics['rsi'] ?? null,
                BuySignalDeterminationService::RSI_OVERSOLD_THRESHOLD,
                'lte',
                fn (float $v) => number_format($v, 1),
            ),
            $this->row(
                '52週安値からの距離',
                '≤+10%',
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['week52_low'] ?? null),
                (BuySignalDeterminationService::WEEK52_LOW_PROXIMITY_RATE - 1) * 100,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'ボリンジャー下限乖離',
                '≤0%',
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['bb_lower'] ?? null),
                // BuySignalDeterminationService::determineBollingerOversold()は
                // 「終値≦bb_lower」（乖離率換算で0%以下）を基準とするため0.0。
                0.0,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'MACD-シグナル線',
                '>0',
                $this->macdDiff($metrics['macd'] ?? null, $metrics['macd_signal'] ?? null),
                BuySignalDeterminationService::MACD_CROSS_THRESHOLD,
                'gt',
                fn (float $v) => number_format($v, 2),
            ),
            $this->row(
                'MA20乖離率',
                '≤-10%',
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['ma20'] ?? null),
                BuySignalDeterminationService::MA_DEVIATION_OVERSOLD_PCT,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'PEGレシオ',
                sprintf('≤%s', number_format(BuySignalDeterminationService::PEG_UNDERVALUED_THRESHOLD, 1)),
                $metrics['peg_ratio'] ?? null,
                BuySignalDeterminationService::PEG_UNDERVALUED_THRESHOLD,
                'lte',
                fn (float $v) => number_format($v, 2),
            ),
            $this->row(
                '出来高倍率',
                sprintf('≥%s倍', number_format(BuySignalDeterminationService::VOLUME_SPIKE_RATIO, 1)),
                $this->ratio($metrics['volume'] ?? null, $metrics['volume_ma20'] ?? null),
                BuySignalDeterminationService::VOLUME_SPIKE_RATIO,
                'gte',
                fn (float $v) => number_format($v, 2).'倍',
            ),
        ];

        $fundamental = $this->fundamentalRows($metrics);

        return [
            'technical' => $technical,
            'fundamental' => $fundamental,
            'summary' => [
                'technical' => $this->summarize($technical),
                'fundamental' => $this->summarize($fundamental),
            ],
        ];
    }

    /**
     * 財務健全性3項目（UC-004/UC-010共通、FundamentalHealthEvaluatorの基準を
     * そのまま可視化）。
     *
     * @param  array<string, float|null>  $metrics
     * @return list<array{label: string, threshold_label: string, value_label: string, status: string}>
     */
    private function fundamentalRows(array $metrics): array
    {
        return [
            $this->row(
                'ROE',
                sprintf('≥%d%%', (int) FundamentalHealthEvaluator::MIN_ROE),
                $metrics['roe'] ?? null,
                FundamentalHealthEvaluator::MIN_ROE,
                'gte',
                fn (float $v) => number_format($v, 1).'%',
            ),
            $this->row(
                '自己資本比率',
                sprintf('≥%d%%', (int) FundamentalHealthEvaluator::MIN_EQUITY_RATIO),
                $metrics['equity_ratio'] ?? null,
                FundamentalHealthEvaluator::MIN_EQUITY_RATIO,
                'gte',
                fn (float $v) => number_format($v, 1).'%',
            ),
            $this->row(
                '成長率',
                '>0%',
                $this->higherGrowthRate($metrics['revenue_growth'] ?? null, $metrics['operating_income_growth'] ?? null),
                FundamentalHealthEvaluator::MIN_GROWTH_RATE,
                'gt',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
        ];
    }

    /**
     * @param  callable(float): string  $formatValue
     * @return array{label: string, threshold_label: string, value_label: string, status: string}
     */
    private function row(
        string $label,
        string $thresholdLabel,
        ?float $value,
        float $threshold,
        string $direction,
        callable $formatValue,
    ): array {
        return [
            'label' => $label,
            'threshold_label' => $thresholdLabel,
            'value_label' => $value === null ? self::UNAVAILABLE_LABEL : $formatValue($value),
            'status' => $this->classify($value, $threshold, $direction),
        ];
    }

    private function classify(?float $value, float $threshold, string $direction): string
    {
        if ($value === null) {
            return 'unavailable';
        }

        $buffer = abs($threshold) * self::NEAR_BUFFER_RATE;

        if ($direction === 'gte') {
            if ($value >= $threshold) {
                return 'met';
            }

            if ($buffer > 0.0 && $value >= $threshold - $buffer) {
                return 'near';
            }

            return 'unmet';
        }

        if ($direction === 'lt') {
            if ($value < $threshold) {
                return 'met';
            }

            if ($buffer > 0.0 && $value <= $threshold + $buffer) {
                return 'near';
            }

            return 'unmet';
        }

        if ($direction === 'gt') {
            if ($value > $threshold) {
                return 'met';
            }

            if ($buffer > 0.0 && $value >= $threshold - $buffer) {
                return 'near';
            }

            return 'unmet';
        }

        // lte
        if ($value <= $threshold) {
            return 'met';
        }

        if ($buffer > 0.0 && $value <= $threshold + $buffer) {
            return 'near';
        }

        return 'unmet';
    }

    /**
     * @param  list<array{status: string}>  $rows
     * @return array{met: int, near: int, total: int}
     */
    private function summarize(array $rows): array
    {
        return [
            'met' => count(array_filter($rows, fn (array $row) => $row['status'] === 'met')),
            'near' => count(array_filter($rows, fn (array $row) => $row['status'] === 'near')),
            'total' => count($rows),
        ];
    }

    /**
     * (value - reference) / reference * 100. null when either input is
     * missing or the reference is 0 (division-by-zero guard).
     */
    private function percentDeviation(?float $value, ?float $reference): ?float
    {
        if ($value === null || $reference === null || $reference == 0.0) {
            return null;
        }

        return ($value - $reference) / $reference * 100;
    }

    private function macdDiff(?float $macd, ?float $macdSignal): ?float
    {
        if ($macd === null || $macdSignal === null) {
            return null;
        }

        return $macd - $macdSignal;
    }

    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * 成長率は売上高・営業利益成長率の高い方を採用する（UC-004/UC-010業務
     * ルール「成長率（売上高・営業利益の高い方）」）。両方nullのときのみ
     * unavailable。
     */
    private function higherGrowthRate(?float $revenueGrowth, ?float $operatingIncomeGrowth): ?float
    {
        if ($revenueGrowth === null && $operatingIncomeGrowth === null) {
            return null;
        }

        if ($revenueGrowth === null) {
            return $operatingIncomeGrowth;
        }

        if ($operatingIncomeGrowth === null) {
            return $revenueGrowth;
        }

        return max($revenueGrowth, $operatingIncomeGrowth);
    }
}
