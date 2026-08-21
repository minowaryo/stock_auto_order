<?php

namespace App\Services\MarketData;

/**
 * Fetches weekly price history for JP-listed stocks by delegating to
 * YahooFinanceChartClient with a ".T" suffix appended to the securities code.
 *
 * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1)
 */
final class JpStockPriceClient implements JpStockPriceClientInterface
{
    private const DEFAULT_WEEKS = 104;

    public function __construct(private readonly YahooFinanceChartClient $client) {}

    public function fetchWeeklyPriceHistory(string $symbolCode): array
    {
        return $this->client->fetchWeeklyHistory($symbolCode.'.T', self::DEFAULT_WEEKS);
    }
}
