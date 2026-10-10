<?php

namespace App\Services\MarketData;

interface UsStockPriceClientInterface
{
    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyPriceHistory(string $symbolCode): array;

    /**
     * The same 104 weeks plus the stock splits of the same request (UC-018).
     */
    public function fetchWeeklyPriceHistoryWithSplits(string $symbolCode): PriceHistory;
}
