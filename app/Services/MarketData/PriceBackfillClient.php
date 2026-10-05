<?php

namespace App\Services\MarketData;

use InvalidArgumentException;

/**
 * Long weekly price history for the one-time price backfill (ADR-0024 D5):
 * 10 years of weekly bars (range=5y would start in 2021-10, too short for
 * the 75-week moving average of 2022 trades; range=max turns old data into
 * monthly bars), with stock splits for stocks. Maps market / index names to
 * Yahoo symbols and delegates to YahooFinanceChartClient.
 */
final class PriceBackfillClient implements PriceBackfillClientInterface
{
    private const RANGE = '10y';

    private const INDEX_SYMBOLS = [
        'nikkei225' => '^N225',
        'sp500' => '^GSPC',
        'sox' => '^SOX',
        'usdjpy' => 'JPY=X',
    ];

    public function __construct(private readonly YahooFinanceChartClient $client) {}

    public function fetchStock(string $market, string $symbolCode): PriceHistory
    {
        $symbol = match ($market) {
            'jp' => $symbolCode.'.T',
            'us' => $symbolCode,
            default => throw new InvalidArgumentException("Unsupported market: {$market}"),
        };

        return $this->client->fetchHistory($symbol, self::RANGE, true);
    }

    public function fetchIndex(string $indexName): PriceHistory
    {
        if (! array_key_exists($indexName, self::INDEX_SYMBOLS)) {
            throw new InvalidArgumentException("Unsupported index_name: {$indexName}");
        }

        return $this->client->fetchHistory(self::INDEX_SYMBOLS[$indexName], self::RANGE);
    }
}
