<?php

namespace App\Services\MarketData;

interface MarketIndexClientInterface
{
    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyHistory(string $indexName): array;
}
