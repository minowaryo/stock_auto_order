<?php

namespace App\Services\Concentration;

/**
 * UC-015 / ADR-0021 D2/D3: maps each concentration metric to a verdict level.
 * Thresholds are a working draft (叩き台); boundary values belong to the worse side.
 */
final class ConcentrationVerdictEvaluator
{
    /** PC1 share (%): lower is better. */
    private const PC1_CAUTION = 25.0;

    private const PC1_CONCENTRATED = 45.0;

    /** Effective number of bets: higher is better. */
    private const ENB_OK = 8.0;

    private const ENB_CAUTION = 3.0;

    /** Top-5 weight (%): lower is better. */
    private const TOP5_CAUTION = 30.0;

    private const TOP5_CONCENTRATED = 50.0;

    /** Absolute SOX beta: lower is better. */
    private const BETA_CAUTION = 0.5;

    private const BETA_CONCENTRATED = 0.8;

    /** Correlation band lower bounds (inclusive). */
    private const CORR_STRONG = 0.7;

    private const CORR_HIGH = 0.5;

    private const CORR_MILD = 0.3;

    private const CORR_NEGATIVE = -0.3;

    public function pc1(?float $percent): ?string
    {
        return $this->lowerIsBetter($percent, self::PC1_CAUTION, self::PC1_CONCENTRATED);
    }

    public function effectiveBets(?float $bets): ?string
    {
        if (! $this->isJudgeable($bets)) {
            return null;
        }

        return match (true) {
            $bets >= self::ENB_OK => 'ok',
            $bets >= self::ENB_CAUTION => 'caution',
            default => 'concentrated',
        };
    }

    public function top5(?float $percent): ?string
    {
        return $this->lowerIsBetter($percent, self::TOP5_CAUTION, self::TOP5_CONCENTRATED);
    }

    public function soxBeta(?float $beta): ?string
    {
        return $this->lowerIsBetter($beta === null ? null : abs($beta), self::BETA_CAUTION, self::BETA_CONCENTRATED);
    }

    private function isJudgeable(?float $value): bool
    {
        return $value !== null && is_finite($value);
    }

    public function correlationBand(?float $correlation): ?string
    {
        if (! $this->isJudgeable($correlation)) {
            return null;
        }

        return match (true) {
            $correlation >= self::CORR_STRONG => 'strong',
            $correlation >= self::CORR_HIGH => 'high',
            $correlation >= self::CORR_MILD => 'mild',
            $correlation > self::CORR_NEGATIVE => 'none',
            default => 'negative',
        };
    }

    private function lowerIsBetter(?float $value, float $caution, float $concentrated): ?string
    {
        if (! $this->isJudgeable($value)) {
            return null;
        }

        return match (true) {
            $value >= $concentrated => 'concentrated',
            $value >= $caution => 'caution',
            default => 'ok',
        };
    }
}
