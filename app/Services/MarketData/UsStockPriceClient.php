<?php

namespace App\Services\MarketData;

/**
 * Fetches weekly price history for US-listed stocks by delegating to
 * YahooFinanceChartClient with the ticker passed through unmodified.
 *
 * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1)
 */
final class UsStockPriceClient implements UsStockPriceClientInterface
{
    private const DEFAULT_WEEKS = 104;

    public function __construct(private readonly YahooFinanceChartClient $client) {}

    public function fetchWeeklyPriceHistory(string $symbolCode): array
    {
        return $this->client->fetchWeeklyHistory($symbolCode, self::DEFAULT_WEEKS);
    }
}
