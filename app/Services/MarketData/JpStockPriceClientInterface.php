<?php

namespace App\Services\MarketData;

interface JpStockPriceClientInterface
{
    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyPriceHistory(string $symbolCode): array;
}
