<?php

namespace App\Services\MarketData;

interface FinnhubClientInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetchMetrics(string $symbolCode): ?array;

    /**
     * Finnhub `/stock/profile2` の `finnhubIndustry`（CHG-0029 / ADR-0020）。
     * 銘柄が見つからない・業種が空の場合は null。
     */
    public function fetchIndustry(string $symbolCode): ?string;

    /**
     * @return array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>
     */
    public function fetchReportedFinancials(string $symbolCode, int $periods = 2): array;
}
