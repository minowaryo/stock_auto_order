<?php

namespace App\Services\Analysis;

/**
 * Determines which take-profit signal types fire for a given weekly price
 * history, by comparing "this week" vs "one week ago" technical indicator
 * snapshots (computed via TechnicalIndicatorCalculator) against fixed
 * thresholds (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md,
 * docs/architecture/data-model.md `signals` table).
 *
 * Pure calculation logic only — no DB/HTTP dependency.
 */
final class SignalDeterminationService
{
    public function __construct(private readonly TechnicalIndicatorCalculator $calculator) {}

    /**
     * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory  Ascending (oldest-first) weekly price history.
     * @return array<int, array{signal_type: string, reason_summary: string}>
     */
    public function determine(
        array $priceHistory,
        ?float $marketReturn13w = null,
        ?float $sectorReturn13w = null,
        ?float $pegRatio = null,
    ): array {
        $current = $this->calculator->calculate($priceHistory, $marketReturn13w, $sectorReturn13w);
        $previous = $this->calculator->calculate(array_slice($priceHistory, 0, -1));

        $lastClose = count($priceHistory) >= 1 ? (float) $priceHistory[count($priceHistory) - 1]['close'] : null;
        $previousClose = count($priceHistory) >= 2 ? (float) $priceHistory[count($priceHistory) - 2]['close'] : null;

        $signals = [];

        if (($signal = $this->determineRsiReversal($previous, $current)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineMacdDeadCross($previous, $current)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineBollingerOverheat($current, $lastClose)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineWeek52HighPullback($current, $lastClose)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determinePegOvervalued($pegRatio)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineRelativeStrengthWeakening($current)) !== null) {
            $signals[] = $signal;
        }

        if (($signal = $this->determineVolumeSpikeDecline($current, $lastClose, $previousClose)) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param  array<string, float|int|null>  $previous
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineRsiReversal(array $previous, array $current): ?array
    {
        $previousRsi = $previous['rsi'];
        $currentRsi = $current['rsi'];

        if ($previousRsi === null || $currentRsi === null) {
            return null;
        }

        if ($previousRsi >= 70 && $currentRsi < $previousRsi) {
            return [
                'signal_type' => 'rsi_reversal',
                'reason_summary' => sprintf(
                    'RSIが%sから%sに反落しました（高水準からの反落）',
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
    private function determineMacdDeadCross(array $previous, array $current): ?array
    {
        $previousMacd = $previous['macd'];
        $previousSignal = $previous['macd_signal'];
        $currentMacd = $current['macd'];
        $currentSignal = $current['macd_signal'];

        if ($previousMacd === null || $previousSignal === null || $currentMacd === null || $currentSignal === null) {
            return null;
        }

        if (($previousMacd - $previousSignal) >= 0 && ($currentMacd - $currentSignal) < 0) {
            return [
                'signal_type' => 'macd_dead_cross',
                'reason_summary' => 'MACDがシグナル線を下抜けました（デッドクロス）',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineBollingerOverheat(array $current, ?float $lastClose): ?array
    {
        $bbUpper = $current['bb_upper'];

        if ($bbUpper === null || $lastClose === null) {
            return null;
        }

        if ($lastClose >= $bbUpper) {
            return [
                'signal_type' => 'bollinger_overheat',
                'reason_summary' => sprintf(
                    '終値%sがボリンジャーバンド上限%sを上回りました',
                    $this->formatNumber($lastClose),
                    $this->formatNumber($bbUpper),
                ),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineWeek52HighPullback(array $current, ?float $lastClose): ?array
    {
        $week52High = $current['week52_high'];

        if ($week52High === null || $lastClose === null) {
            return null;
        }

        if ($lastClose <= $week52High * 0.9) {
            return [
                'signal_type' => 'week52_high_pullback',
                'reason_summary' => sprintf(
                    '52週高値%sから%sまで下落しました',
                    $this->formatNumber($week52High),
                    $this->formatNumber($lastClose),
                ),
            ];
        }

        return null;
    }

    /**
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determinePegOvervalued(?float $pegRatio): ?array
    {
        if ($pegRatio === null) {
            return null;
        }

        if ($pegRatio >= 2.0) {
            return [
                'signal_type' => 'peg_overvalued',
                'reason_summary' => sprintf('PEGレシオが%sと割高水準です', $this->formatNumber($pegRatio, 1)),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineRelativeStrengthWeakening(array $current): ?array
    {
        $relativeStrength = $current['relative_strength_vs_market'];

        if ($relativeStrength === null) {
            return null;
        }

        if ($relativeStrength < 0) {
            return [
                'signal_type' => 'relative_strength_weakening',
                'reason_summary' => sprintf('対市場の相対力が%sと劣後しています', $this->formatNumber($relativeStrength)),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @return array{signal_type: string, reason_summary: string}|null
     */
    private function determineVolumeSpikeDecline(array $current, ?float $lastClose, ?float $previousClose): ?array
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

        if ($ratio >= 1.5 && $lastClose < $previousClose) {
            return [
                'signal_type' => 'volume_spike_decline',
                'reason_summary' => sprintf(
                    '出来高が20週平均の%s倍に急増し、株価が下落しました',
                    $this->formatNumber($ratio, 1),
                ),
            ];
        }

        return null;
    }

    private function formatNumber(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals);
    }
}
