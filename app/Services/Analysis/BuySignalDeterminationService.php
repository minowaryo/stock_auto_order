<?php

namespace App\Services\Analysis;

/**
 * Determines which buy-on-dip (押し目買い) signal types fire for a given
 * weekly price history, by comparing "this week" vs "one week ago"
 * technical indicator snapshots (computed via TechnicalIndicatorCalculator)
 * against fixed thresholds (docs/product/use-cases.md UC-010, ADR-0007,
 * docs/architecture/data-model.md `buy_signals` table).
 *
 * Mirrors SignalDeterminationService's structure with the 7 signal
 * conditions reversed (押し目/反発方向), gated by the all-signals-common
 * preconditions added 2026-08-23 (ADR-0007 Addendum): none of the 7 signal
 * types may fire unless BOTH (A) the stock recently approached its 52-week
 * high and (B) it is not underperforming the market by more than -5pt.
 *
 * Pure calculation logic only — no DB/HTTP dependency.
 */
final class BuySignalDeterminationService
{
    /**
     * All-signals-common precondition A: within the last N weeks, at least
     * one close must be within -15% of week52_high.
     */
    private const RECENT_STRENGTH_WINDOW_WEEKS = 13;

    private const RECENT_STRENGTH_THRESHOLD_RATE = 0.85;

    /**
     * All-signals-common precondition B: relative_strength_vs_market must
     * not be null and must be >= this value.
     *
     * Promoted to `public` (2026-09-06, CHG-0010) so
     * App\Services\Analysis\SignalCriteriaEvaluator::evaluateLossReview()
     * (整理チェックリスト⑥ 相対力(対市場)) can reference the same -5.0 source
     * of truth instead of re-declaring it. Value unchanged; the existing
     * preconditionsSatisfied() reference keeps working.
     */
    public const MIN_RELATIVE_STRENGTH = -5.0;

    /**
     * Threshold constants, promoted from inline literals (2026-08-29,
     * CHG-0007) so App\Services\Analysis\SignalCriteriaEvaluator (判定
     * チェックリスト) can reference the same source of truth instead of
     * re-declaring these values (avoids the CHG-0005-style duplicate
     * threshold drift). Values are unchanged from the pre-existing literals.
     */
    public const RSI_OVERSOLD_THRESHOLD = 30.0;

    public const MACD_CROSS_THRESHOLD = 0.0;

    public const WEEK52_LOW_PROXIMITY_RATE = 1.10;

    public const MA_DEVIATION_OVERSOLD_PCT = -10.0;

    public const PEG_UNDERVALUED_THRESHOLD = 1.0;

    public const VOLUME_SPIKE_RATIO = 1.5;

    /**
     * PER単体シグナル（CHG-0018 / ADR-0015 D2）の閾値。PBRはAND条件に含めない
     * （ADR-0015 D2 実測検証セクション参照）。
     */
    public const PER_UNDERVALUED_THRESHOLD = 15.0;

    public function __construct(
        private readonly TechnicalIndicatorCalculator $calculator,
        private readonly FundamentalHealthEvaluator $healthEvaluator,
    ) {}

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory  Ascending (oldest-first) weekly price history.
     * @return array<int, array{signal_type: string, reason_summary: string}>
     */
    public function determine(
        array $priceHistory,
        ?float $marketReturn13w = null,
        ?float $sectorReturn13w = null,
        ?float $pegRatio = null,
        ?float $per = null,
        ?float $equityRatio = null,
        ?float $roe = null,
        ?float $revenueGrowth = null,
        ?float $operatingIncomeGrowth = null,
        ?float $operatingMargin = null,
    ): array {
        $current = $this->calculator->calculate($priceHistory, $marketReturn13w, $sectorReturn13w);

        $fundamentalStatus = $this->healthEvaluator->evaluate(
            $equityRatio,
            $roe,
            $revenueGrowth,
            $operatingIncomeGrowth,
            $operatingMargin,
        );

        if (! $this->preconditionsSatisfied($priceHistory, $current, $fundamentalStatus)) {
            return [];
        }

        $previous = $this->calculator->calculate(array_slice($priceHistory, 0, -1));

        $lastClose = count($priceHistory) >= 1 ? (float) $priceHistory[count($priceHistory) - 1]['close'] : null;
        $previousClose = count($priceHistory) >= 2 ? (float) $priceHistory[count($priceHistory) - 2]['close'] : null;

        $signals = [];

        if (($signal = $this->determineRsiOversoldRebound($previous, $current)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineMacdGoldenCross($previous, $current)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineBollingerOversold($current, $lastClose)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineWeek52LowProximity($current, $lastClose)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineMaDeviationOversold($current, $lastClose)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineVolumeSpikeRebound($current, $lastClose, $previousClose)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determinePegUndervalued($pegRatio)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determinePerUndervalued($per)) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
     * @param  array<string, float|int|null>  $current
     */
    private function preconditionsSatisfied(array $priceHistory, array $current, string $fundamentalStatus): bool
    {
        // 前提条件A（ADR-0015 D1）: 価格面（直近13週以内に52週高値-15%以内へ
        // 到達）または財務健全性passedのOR条件に緩和する。
        $recentlyStrong = $this->recentlyNearWeek52High($priceHistory, $current) || $fundamentalStatus === 'passed';

        if (! $recentlyStrong) {
            return false;
        }

        $relativeStrength = $current['relative_strength_vs_market'];

        if ($relativeStrength === null || $relativeStrength < self::MIN_RELATIVE_STRENGTH) {
            return false;
        }

        return true;
    }

    /**
     * 前提条件Aの価格面判定: 直近13週以内に終値が week52_high * 0.85 以上へ
     * 到達しているか。
     *
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
     * @param  array<string, float|int|null>  $current
     */
    private function recentlyNearWeek52High(array $priceHistory, array $current): bool
    {
        $week52High = $current['week52_high'];

        if ($week52High === null) {
            return false;
        }

        $threshold = $week52High * self::RECENT_STRENGTH_THRESHOLD_RATE;
        $recentWindow = array_slice($priceHistory, -self::RECENT_STRENGTH_WINDOW_WEEKS);

        foreach ($recentWindow as $row) {
            if ((float) $row['close'] >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, float|int|null>  $previous
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineRsiOversoldRebound(array $previous, array $current): ?array
    {
        $previousRsi = $previous['rsi'];
        $currentRsi = $current['rsi'];

        if ($previousRsi === null || $currentRsi === null) {
            return null;
        }

        if ($previousRsi <= self::RSI_OVERSOLD_THRESHOLD && $currentRsi > $previousRsi) {
            return [
                'signal_type' => 'rsi_oversold_rebound',
                'reason_summary' => sprintf(
                    'RSIが%sから%sへ反発しました',
                    $this->formatNumber($previousRsi),
                    $this->formatNumber($currentRsi),
                ),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $previous
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineMacdGoldenCross(array $previous, array $current): ?array
    {
        $previousMacd = $previous['macd'];
        $previousSignal = $previous['macd_signal'];
        $currentMacd = $current['macd'];
        $currentSignal = $current['macd_signal'];

        if ($previousMacd === null || $previousSignal === null || $currentMacd === null || $currentSignal === null) {
            return null;
        }

        if (($previousMacd - $previousSignal) <= self::MACD_CROSS_THRESHOLD && ($currentMacd - $currentSignal) > self::MACD_CROSS_THRESHOLD) {
            return [
                'signal_type' => 'macd_golden_cross',
                'reason_summary' => 'MACDがシグナル線を上抜けました（ゴールデンクロス）',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineBollingerOversold(array $current, ?float $lastClose): ?array
    {
        $bbLower = $current['bb_lower'];

        if ($bbLower === null || $lastClose === null) {
            return null;
        }

        if ($lastClose <= $bbLower) {
            return [
                'signal_type' => 'bollinger_oversold',
                'reason_summary' => sprintf(
                    '終値%sがボリンジャーバンド下限%sを下回りました',
                    $this->formatNumber($lastClose),
                    $this->formatNumber($bbLower),
                ),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineWeek52LowProximity(array $current, ?float $lastClose): ?array
    {
        $week52Low = $current['week52_low'];

        if ($week52Low === null || $lastClose === null) {
            return null;
        }

        if ($lastClose <= $week52Low * self::WEEK52_LOW_PROXIMITY_RATE) {
            return [
                'signal_type' => 'week52_low_proximity',
                'reason_summary' => sprintf(
                    '52週安値%sに近い%sまで下落しています',
                    $this->formatNumber($week52Low),
                    $this->formatNumber($lastClose),
                ),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineMaDeviationOversold(array $current, ?float $lastClose): ?array
    {
        $ma20 = $current['ma20'];

        if ($ma20 === null || $lastClose === null || $ma20 == 0.0) {
            return null;
        }

        $deviation = ($lastClose - $ma20) / $ma20 * 100;

        if ($deviation <= self::MA_DEVIATION_OVERSOLD_PCT) {
            return [
                'signal_type' => 'ma_deviation_oversold',
                'reason_summary' => sprintf('MA20から%s%%下方乖離しています', $this->formatNumber($deviation, 2)),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineVolumeSpikeRebound(array $current, ?float $lastClose, ?float $previousClose): ?array
    {
        $volume = $current['volume'];
        $volumeMa20 = $current['volume_ma20'];

        if ($volume === null || $volumeMa20 === null || $lastClose === null || $previousClose === null) {
            return null;
        }

        if ($volumeMa20 == 0.0) {
            return null;
        }

        $ratio = $volume / $volumeMa20;

        if ($ratio >= self::VOLUME_SPIKE_RATIO && $lastClose > $previousClose) {
            return [
                'signal_type' => 'volume_spike_rebound',
                'reason_summary' => sprintf(
                    '出来高が20週平均の%s倍に急増し、株価が反発しました',
                    $this->formatNumber($ratio, 1),
                ),
            ];
        }

        return null;
    }

    /**
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determinePegUndervalued(?float $pegRatio): ?array
    {
        if ($pegRatio === null) {
            return null;
        }

        // ADR-0012 D4: without a lower bound, a negative PEG (declining/
        // negative earnings growth; US Finnhub pegTTM passes negative values
        // through as-is) would be misread as "undervalued". Only 0 < peg <= 1.0
        // counts as undervalued.
        if ($pegRatio > 0.0 && $pegRatio <= self::PEG_UNDERVALUED_THRESHOLD) {
            return [
                'signal_type' => 'peg_undervalued',
                'reason_summary' => sprintf('PEGレシオが%sと割安水準です', $this->formatNumber($pegRatio, 1)),
            ];
        }

        return null;
    }

    /**
     * PER単体シグナル（CHG-0018 / ADR-0015 D2）。PBRはAND条件に含めない。
     *
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determinePerUndervalued(?float $per): ?array
    {
        if ($per === null) {
            return null;
        }

        if ($per <= self::PER_UNDERVALUED_THRESHOLD) {
            return [
                'signal_type' => 'per_undervalued',
                'reason_summary' => sprintf('PERが%sと割安水準です', $this->formatNumber($per, 1)),
            ];
        }

        return null;
    }

    private function formatNumber(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals);
    }
}
