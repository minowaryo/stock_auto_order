<?php

namespace App\Services\TradeReview;

use App\Models\TradeExecution;

/**
 * Estimated switches (UC-018 基本フロー5, Gate 2 draft 決定2): sell proceeds
 * are allocated to buys of the same market made on the sell day or up to 5
 * business days (weekdays) later, buys in trade order drawing from the
 * oldest sell first. A yen is never allocated twice; what a buy cannot draw
 * is new money and what a sell keeps is cash. Routine buys (積立) take no part.
 */
class SwitchAllocator
{
    private const WINDOW_BUSINESS_DAYS = 5;

    /**
     * @return list<array{sell_id: int, buy_id: int, amount_jpy: float}>
     */
    public function allocate(): array
    {
        $trades = TradeExecution::query()
            ->whereIn('kind', ['sell', 'buy'])
            ->orderBy('trade_date')
            ->orderBy('id')
            ->get();

        $open = [];
        $allocations = [];

        foreach ($trades as $trade) {
            if ($trade->kind === 'sell') {
                $open[] = [
                    'trade' => $trade,
                    'until' => $trade->trade_date->copy()->addWeekdays(self::WINDOW_BUSINESS_DAYS)->toDateString(),
                    'left' => self::amountJpy($trade),
                ];

                continue;
            }

            $need = self::amountJpy($trade);
            $date = $trade->trade_date->toDateString();

            foreach ($open as $i => $sell) {
                if ($need <= 0) {
                    break;
                }

                if ($sell['left'] <= 0 || $sell['trade']->market !== $trade->market || $sell['until'] < $date) {
                    continue;
                }

                $amount = min($sell['left'], $need);
                $open[$i]['left'] -= $amount;
                $need -= $amount;
                $allocations[] = ['sell_id' => $sell['trade']->id, 'buy_id' => $trade->id, 'amount_jpy' => $amount];
            }
        }

        return $allocations;
    }

    /**
     * The JPY settlement of a buy or sell (USD settlements at the CSV rate).
     */
    public static function amountJpy(TradeExecution $trade): float
    {
        if ($trade->settlement_amount_jpy !== null) {
            return (float) $trade->settlement_amount_jpy;
        }

        return (float) $trade->settlement_amount_usd * (float) $trade->fx_rate;
    }
}
