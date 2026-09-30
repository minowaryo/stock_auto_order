<?php

namespace App\Services\Concentration;

final class PrincipalComponentAnalyzer
{
    private const EPSILON = 1e-18;

    public function __construct(private readonly EigenSolver $eigenSolver = new EigenSolver) {}

    /**
     * Largest correlation-matrix eigenvalue over the number of non-constant series, via the T×T dual form.
     *
     * @param  array<int, list<float>>  $returns
     */
    public function firstComponentShare(array $returns): ?float
    {
        $z = [];
        foreach ($returns as $series) {
            $t = count($series);
            $mean = array_sum($series) / $t;
            $c = array_map(fn ($x) => $x - $mean, $series);
            $ss = array_sum(array_map(fn ($x) => $x * $x, $c));
            if ($ss <= self::EPSILON) {
                continue;
            }
            $std = sqrt($ss / ($t - 1));
            $z[] = array_map(fn ($x) => $x / $std, $c);
        }

        if (count($z) < 2) {
            return null;
        }

        $t = count($z[0]);
        $gram = [];
        for ($a = 0; $a < $t; $a++) {
            for ($b = $a; $b < $t; $b++) {
                $s = 0.0;
                foreach ($z as $row) {
                    $s += $row[$a] * $row[$b];
                }
                $gram[$a][$b] = $gram[$b][$a] = $s / ($t - 1);
            }
        }

        $eig = $this->eigenSolver->symmetric($gram);

        return $eig['values'][0] / count($z);
    }
}
