<?php

namespace App\Services\MarketData;

interface JQuantsClientInterface
{
    /**
     * @return array{code: string, name: string}|null
     */
    public function fetchSectorInfo(string $symbolCode): ?array;

    /**
     * @return array<int, array{
     *     disclosed_date: string,
     *     net_sales: float|null,
     *     operating_profit: float|null,
     *     profit: float|null,
     *     eps: float|null,
     *     book_value_per_share: float|null,
     *     equity_to_asset_ratio: float|null,
     *     roe: float|null,
     *     dividend_per_share_annual: float|null,
     *     payout_ratio_annual: float|null,
     * }>
     */
    public function fetchStatements(string $symbolCode, int $periods = 5): array;
}
