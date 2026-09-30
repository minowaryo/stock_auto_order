<?php

namespace App\Services\Concentration;

final class TopHoldingsConcentrationCalculator
{
    /**
     * @param  array<int, int|float>  $marketValues
     */
    public function topWeight(array $marketValues, int $n = 5): ?float
    {
        $total = array_sum($marketValues);
        if ($marketValues === [] || $total <= 0) {
            return null;
        }

        $values = array_values($marketValues);
        rsort($values);

        return array_sum(array_slice($values, 0, $n)) / $total;
    }
}
