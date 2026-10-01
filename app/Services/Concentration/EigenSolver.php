<?php

namespace App\Services\Concentration;

final class EigenSolver
{
    private const MAX_SWEEPS = 100;

    private const TOLERANCE = 1e-14;

    /**
     * Cyclic Jacobi eigenvalue algorithm for a real symmetric matrix.
     *
     * @param  list<list<int|float>>  $matrix
     * @return array{values: list<float>, vectors: list<list<float>>} eigenvectors as unit-length rows, values descending
     */
    public function symmetric(array $matrix): array
    {
        $n = count($matrix);
        if ($n === 0) {
            return ['values' => [], 'vectors' => []];
        }

        $a = [];
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $a[$i][$j] = (float) $matrix[$i][$j];
                $v[$i][$j] = $i === $j ? 1.0 : 0.0;
            }
        }

        $total = 0.0;
        foreach ($a as $row) {
            foreach ($row as $x) {
                $total += $x * $x;
            }
        }

        for ($sweep = 0; $sweep < self::MAX_SWEEPS; $sweep++) {
            $off = 0.0;
            for ($p = 0; $p < $n - 1; $p++) {
                for ($q = $p + 1; $q < $n; $q++) {
                    $off += $a[$p][$q] * $a[$p][$q];
                }
            }
            if (sqrt(2 * $off) <= self::TOLERANCE * sqrt($total)) {
                break;
            }

            for ($p = 0; $p < $n - 1; $p++) {
                for ($q = $p + 1; $q < $n; $q++) {
                    if ($a[$p][$q] == 0.0) {
                        continue;
                    }
                    $theta = ($a[$q][$q] - $a[$p][$p]) / (2 * $a[$p][$q]);
                    $t = ($theta >= 0 ? 1.0 : -1.0) / (abs($theta) + sqrt($theta * $theta + 1));
                    $c = 1 / sqrt($t * $t + 1);
                    $s = $t * $c;

                    for ($k = 0; $k < $n; $k++) {
                        $akp = $a[$k][$p];
                        $akq = $a[$k][$q];
                        $a[$k][$p] = $c * $akp - $s * $akq;
                        $a[$k][$q] = $s * $akp + $c * $akq;
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $apk = $a[$p][$k];
                        $aqk = $a[$q][$k];
                        $a[$p][$k] = $c * $apk - $s * $aqk;
                        $a[$q][$k] = $s * $apk + $c * $aqk;
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $vkp = $v[$k][$p];
                        $vkq = $v[$k][$q];
                        $v[$k][$p] = $c * $vkp - $s * $vkq;
                        $v[$k][$q] = $s * $vkp + $c * $vkq;
                    }
                }
            }
        }

        $order = range(0, $n - 1);
        usort($order, fn (int $x, int $y) => $a[$y][$y] <=> $a[$x][$x]);

        $values = [];
        $vectors = [];
        foreach ($order as $idx) {
            $values[] = $a[$idx][$idx];
            $column = [];
            for ($k = 0; $k < $n; $k++) {
                $column[] = $v[$k][$idx];
            }
            $vectors[] = $column;
        }

        return ['values' => $values, 'vectors' => $vectors];
    }
}
