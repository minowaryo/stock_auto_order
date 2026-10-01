<?php

namespace App\Services\Concentration;

final class HoldingWeightCalculator
{
    /**
     * @param  list<array{holding_id: int, instrument_type: string, quantity: int|float|string, current_price: int|float|string}>  $positions
     * @return array<int, float> holding_id => market value
     */
    public function marketValues(array $positions): array
    {
        $values = [];
        foreach ($positions as $position) {
            $value = (float) $position['quantity'] * (float) $position['current_price'];
            // Mutual fund NAV is quoted per 10,000 units.
            if ($position['instrument_type'] === 'mutual_fund') {
                $value /= 10000;
            }
            $values[$position['holding_id']] = $value;
        }

        return $values;
    }

    /**
     * @param  array<int, float>  $marketValues
     * @param  list<int>  $holdingIds
     * @return array<int, float> holding_id => weight (sums to 1.0 over the subset)
     */
    public function normalize(array $marketValues, array $holdingIds): array
    {
        $subset = [];
        foreach ($holdingIds as $id) {
            if (array_key_exists($id, $marketValues)) {
                $subset[$id] = $marketValues[$id];
            }
        }

        $total = array_sum($subset);
        if ($total == 0) {
            return [];
        }

        return array_map(fn (float $value) => $value / $total, $subset);
    }
}
