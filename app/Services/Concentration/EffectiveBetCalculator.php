<?php

namespace App\Services\Concentration;

final class EffectiveBetCalculator
{
    private const EPSILON = 1e-18;

    private const PROBABILITY_FLOOR = 1e-15;

    public function __construct(private readonly EigenSolver $eigenSolver = new EigenSolver) {}

    /**
     * Meucci effective number of bets, via the T×T dual form so N may exceed T.
     *
     * @param  array<int, list<float>>  $returns
     * @param  array<int, float>  $weights
     */
    public function calculate(array $returns, array $weights): ?float
    {
        if ($returns === []) {
            return null;
        }

        $x = [];
        $w = [];
        foreach ($returns as $id => $series) {
            $mean = array_sum($series) / count($series);
            $x[] = array_map(fn ($r) => $r - $mean, $series);
            $w[] = (float) ($weights[$id] ?? 0.0);
        }

        $t = count($x[0]);
        $gram = [];
        for ($a = 0; $a < $t; $a++) {
            for ($b = $a; $b < $t; $b++) {
                $s = 0.0;
                foreach ($x as $row) {
                    $s += $row[$a] * $row[$b];
                }
                $gram[$a][$b] = $gram[$b][$a] = $s / ($t - 1);
            }
        }

        $y = array_fill(0, $t, 0.0);
        foreach ($x as $i => $row) {
            foreach ($row as $k => $r) {
                $y[$k] += $w[$i] * $r;
            }
        }

        $eig = $this->eigenSolver->symmetric($gram);
        $v = [];
        foreach ($eig['vectors'] as $u) {
            $proj = 0.0;
            foreach ($u as $k => $e) {
                $proj += $e * $y[$k];
            }
            $v[] = $proj * $proj / ($t - 1);
        }

        $total = array_sum($v);
        if ($total <= self::EPSILON) {
            return null;
        }

        $entropy = 0.0;
        foreach ($v as $contribution) {
            $p = $contribution / $total;
            if ($p > self::PROBABILITY_FLOOR) {
                $entropy -= $p * log($p);
            }
        }

        return exp($entropy);
    }
}
