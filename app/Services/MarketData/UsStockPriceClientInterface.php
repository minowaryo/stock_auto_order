<?php

namespace App\Services\MarketData;

interface UsStockPriceClientInterface
{
    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyPriceHistory(string $symbolCode): array;
}
