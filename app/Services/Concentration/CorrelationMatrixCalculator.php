<?php

namespace App\Services\Concentration;

final class CorrelationMatrixCalculator
{
    private const EPSILON = 1e-18;

    /**
     * @param  array<int, list<float>>  $returns
     * @return array<int, array<int, float|null>> Pearson matrix keyed [id_i][id_j]; constant series yield null
     */
    public function calculate(array $returns): array
    {
        $centered = [];
        $norm = [];
        foreach ($returns as $id => $series) {
            $mean = array_sum($series) / count($series);
            $c = array_map(fn ($x) => $x - $mean, $series);
            $ss = array_sum(array_map(fn ($x) => $x * $x, $c));
            $centered[$id] = $c;
            $norm[$id] = $ss > self::EPSILON ? sqrt($ss) : null;
        }

        $matrix = [];
        foreach ($returns as $i => $_) {
            foreach ($returns as $j => $__) {
                if ($norm[$i] === null || $norm[$j] === null) {
                    $matrix[$i][$j] = null;

                    continue;
                }
                if ($i === $j) {
                    $matrix[$i][$j] = 1.0;

                    continue;
                }
                $dot = 0.0;
                foreach ($centered[$i] as $k => $x) {
                    $dot += $x * $centered[$j][$k];
                }
                $matrix[$i][$j] = $dot / ($norm[$i] * $norm[$j]);
            }
        }

        return $matrix;
    }
}
