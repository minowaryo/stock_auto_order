<?php

namespace App\Services\Concentration;

final class SoxBetaCalculator
{
    private const EPSILON = 1e-18;

    /**
     * @param  array<int, list<float>>  $returns
     * @param  list<float>  $soxReturns
     * @return array<int, float|null> null for every id when the SOX variance is ~0
     */
    public function betas(array $returns, array $soxReturns): array
    {
        $t = count($soxReturns);
        $soxMean = array_sum($soxReturns) / $t;
        $soxDev = array_map(fn ($x) => $x - $soxMean, $soxReturns);
        $soxVar = array_sum(array_map(fn ($x) => $x * $x, $soxDev)) / ($t - 1);

        $betas = [];
        foreach ($returns as $id => $series) {
            if ($soxVar <= self::EPSILON) {
                $betas[$id] = null;

                continue;
            }
            $mean = array_sum($series) / $t;
            $cov = 0.0;
            foreach ($series as $k => $r) {
                $cov += ($r - $mean) * $soxDev[$k];
            }
            $betas[$id] = ($cov / ($t - 1)) / $soxVar;
        }

        return $betas;
    }

    /**
     * @param  array<int, float|null>  $betas
     * @param  array<int, float>  $weights
     */
    public function portfolioBeta(array $betas, array $weights): ?float
    {
        if ($betas === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($betas as $id => $beta) {
            if ($beta === null) {
                return null;
            }
            $sum += ($weights[$id] ?? 0.0) * $beta;
        }

        return $sum;
    }
}
