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
     * Normalises `holding_snapshots.current_price` to the same currency as
     * `technical_indicators.week52_high` / `week52_low` / `ma20` / `ma75` /
     * `bb_*` before the price-vs-indicator deviation items are computed.
     *
     * US holdings store `current_price` already converted to JPY at import
     * time (UC-001 業務ルール: 参考為替レートで円換算), while the technical
     * indicators are derived from the native-currency (USD) price series.
     * Comparing the two directly blows the deviation up by ~150x. Dividing
     * `current_price` back by `fx_rate_used` puts both in USD. JP holdings
     * have `fx_rate_used = null` and are returned unchanged.
     *
     * Display/price columns outside the criteria checklist (評価額 =
     * market_value〔CHG-0011〕, 分割指値の価格) intentionally stay JPY and do
     * not call this.
     */
    public static function indicatorComparablePrice(?float $currentPrice, ?float $fxRateUsed): ?float
    {
        if ($currentPrice === null) {
            return null;
        }

        if ($fxRateUsed !== null && $fxRateUsed > 0.0) {
            return $currentPrice / $fxRateUsed;
        }

        return $currentPrice;
    }

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
                // ADR-0012 D4: a negative PEG (declining/negative earnings
                // growth) is not undervalued, so it must not read as met/near
                // (the raw value still displays, classified as unmet).
                'lte_positive',
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
     * 整理検討チェックリスト（UC-011 / F-011, CHG-0010 / ADR-0010 D6 改訂
     * 2026-09-06「赤の単一極性」）。テクニカル7項目・財務3項目のいずれも
     * 「整理を後押しする事実が成立している」状態を達成（met）とする。財務3項目は
     * UC-004/UC-010 とは判定の向きを反転し「基準割れ（＝投資根拠の毀損）」を
     * met とする（fundamentalRows($metrics, true)）。返却構造は
     * evaluateTakeProfit()/evaluateBuy() と完全に同一。
     *
     * @param  array<string, float|null>  $metrics
     * @return array{
     *   technical: list<array{label: string, threshold_label: string, value_label: string, status: string}>,
     *   fundamental: list<array{label: string, threshold_label: string, value_label: string, status: string}>,
     *   summary: array{technical: array{met: int, near: int, total: int}, fundamental: array{met: int, near: int, total: int}},
     * }
     */
    public function evaluateLossReview(array $metrics): array
    {
        $technical = [
            $this->row(
                '含み損率',
                sprintf('≤%d%%', (int) LossReviewThresholds::LOSS_REVIEW_LINE),
                $metrics['unrealized_gain_rate'] ?? null,
                LossReviewThresholds::LOSS_REVIEW_LINE,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                '52週高値からの下落率',
                sprintf('≤%d%%', (int) LossReviewThresholds::WEEK52_HIGH_DECLINE_PCT),
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['week52_high'] ?? null),
                LossReviewThresholds::WEEK52_HIGH_DECLINE_PCT,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
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
                'MA75乖離率',
                '≤-10%',
                $this->percentDeviation($metrics['current_price'] ?? null, $metrics['ma75'] ?? null),
                BuySignalDeterminationService::MA_DEVIATION_OVERSOLD_PCT,
                'lte',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                'MACD-シグナル線',
                '<0',
                $this->macdDiff($metrics['macd'] ?? null, $metrics['macd_signal'] ?? null),
                BuySignalDeterminationService::MACD_CROSS_THRESHOLD,
                'lt',
                fn (float $v) => number_format($v, 2),
            ),
            $this->row(
                '相対力(対市場)',
                '≤-5',
                $metrics['relative_strength_vs_market'] ?? null,
                BuySignalDeterminationService::MIN_RELATIVE_STRENGTH,
                'lte',
                fn (float $v) => sprintf('%+.1f', $v),
            ),
            $this->row(
                '押し目買いシグナル件数',
                '=0件',
                $metrics['rebound_buy_signal_count'] ?? null,
                (float) LossReviewThresholds::NO_REBOUND_SIGNAL_COUNT,
                'lte',
                fn (float $v) => number_format($v, 0).'件',
            ),
        ];

        $fundamental = $this->fundamentalRows($metrics, true);

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
     * 財務健全性4項目（CHG-0012 / ADR-0011 で営業利益率を4項目目に追加）。
     *
     * $forLossReview = false（UC-004/UC-010）: FundamentalHealthEvaluator の
     * 基準をそのまま可視化し「健全＝met」とする。
     * $forLossReview = true（UC-011 / ADR-0010 D6 改訂・ADR-0011 D7）: 判定の
     * 向きを反転し「基準割れ（＝投資根拠の毀損）＝met」とする。閾値の値は同じ
     * 定数を流用し direction と threshold_label のみ反転する。
     *
     * @param  array<string, float|null>  $metrics
     * @return list<array{label: string, threshold_label: string, value_label: string, status: string}>
     */
    private function fundamentalRows(array $metrics, bool $forLossReview = false): array
    {
        $growthRate = self::higherGrowthRate($metrics['revenue_growth'] ?? null, $metrics['operating_income_growth'] ?? null);

        if ($forLossReview) {
            return [
                $this->row(
                    'ROE',
                    sprintf('<%d%%', (int) FundamentalHealthEvaluator::MIN_ROE),
                    $metrics['roe'] ?? null,
                    FundamentalHealthEvaluator::MIN_ROE,
                    'lt',
                    fn (float $v) => number_format($v, 1).'%',
                ),
                $this->row(
                    '自己資本比率',
                    sprintf('<%d%%', (int) FundamentalHealthEvaluator::MIN_EQUITY_RATIO),
                    $metrics['equity_ratio'] ?? null,
                    FundamentalHealthEvaluator::MIN_EQUITY_RATIO,
                    'lt',
                    fn (float $v) => number_format($v, 1).'%',
                ),
                $this->row(
                    '成長率',
                    '≤0%',
                    $growthRate,
                    FundamentalHealthEvaluator::MIN_GROWTH_RATE,
                    'lte',
                    fn (float $v) => sprintf('%+.1f%%', $v),
                ),
                $this->row(
                    '営業利益率',
                    sprintf('<%d%%', (int) FundamentalHealthEvaluator::MIN_OPERATING_MARGIN),
                    $metrics['operating_margin'] ?? null,
                    FundamentalHealthEvaluator::MIN_OPERATING_MARGIN,
                    'lt',
                    fn (float $v) => number_format($v, 1).'%',
                ),
            ];
        }

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
                $growthRate,
                FundamentalHealthEvaluator::MIN_GROWTH_RATE,
                'gt',
                fn (float $v) => sprintf('%+.1f%%', $v),
            ),
            $this->row(
                '営業利益率',
                sprintf('≥%d%%', (int) FundamentalHealthEvaluator::MIN_OPERATING_MARGIN),
                $metrics['operating_margin'] ?? null,
                FundamentalHealthEvaluator::MIN_OPERATING_MARGIN,
                'gte',
                fn (float $v) => number_format($v, 1).'%',
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

        // lte_positive: same as lte, but a non-positive value is never a
        // "met/near" (ADR-0012 D4 — a negative PEG means declining/negative
        // earnings growth, not "cheap").
        if ($direction === 'lte_positive') {
            if ($value <= 0.0) {
                return 'unmet';
            }

            if ($value <= $threshold) {
                return 'met';
            }

            if ($buffer > 0.0 && $value <= $threshold + $buffer) {
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
     * unavailable。ShowLossReviewListAction（UC-011）の fundamental_summary
     * からも同一ロジックで使うため public static とする。
     */
    public static function higherGrowthRate(?float $revenueGrowth, ?float $operatingIncomeGrowth): ?float
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
