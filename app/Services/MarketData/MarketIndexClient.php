<?php

namespace App\Services\MarketData;

use InvalidArgumentException;

/**
 * Fetches weekly price history for market indices by mapping index_name to
 * a Yahoo Finance symbol and delegating to YahooFinanceChartClient.
 *
 * Phase1 only supports nikkei225/sp500. Other index_name values defined in
 * data-model.md (us10y, vix, usdjpy) are reserved for Phase2 (UC-007) and
 * throw InvalidArgumentException until implemented.
 *
 * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§4)
 */
final class MarketIndexClient implements MarketIndexClientInterface
{
    private const DEFAULT_WEEKS = 104;

    private const SYMBOL_MAP = [
        'nikkei225' => '^N225',
        'sp500' => '^GSPC',
    ];

    public function __construct(private readonly YahooFinanceChartClient $client) {}

    public function fetchWeeklyHistory(string $indexName): array
    {
        if (! array_key_exists($indexName, self::SYMBOL_MAP)) {
            throw new InvalidArgumentException("Unsupported index_name: {$indexName}");
        }

        return $this->client->fetchWeeklyHistory(self::SYMBOL_MAP[$indexName], self::DEFAULT_WEEKS);
    }
}
