<?php

namespace App\Services\MarketData;

interface PriceBackfillClientInterface
{
    /**
     * @param  'jp'|'us'  $market
     */
    public function fetchStock(string $market, string $symbolCode): PriceHistory;

    /**
     * @param  'nikkei225'|'sp500'|'sox'|'usdjpy'  $indexName
     */
    public function fetchIndex(string $indexName): PriceHistory;
}
