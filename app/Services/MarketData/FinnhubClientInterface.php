<?php

namespace App\Services\MarketData;

interface FinnhubClientInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetchMetrics(string $symbolCode): ?array;

    /**
     * @return array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>
     */
    public function fetchReportedFinancials(string $symbolCode, int $periods = 2): array;
}
