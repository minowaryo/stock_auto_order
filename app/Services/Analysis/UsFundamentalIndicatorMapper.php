<?php

namespace App\Services\Analysis;

/**
 * Maps a Finnhub `stock/metric` response plus a chronologically descending
 * (latest-first) sequence of `stock/financials-reported`-derived figures
 * into the same market-agnostic fundamental indicator shape produced by
 * FundamentalIndicatorMapper (JP用) — see
 * docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md.
 *
 * Pure calculation/mapping logic only — no DB/HTTP dependency. Unlike
 * FundamentalIndicatorMapper, Finnhub's `metric` fields are already
 * percent-scale ratios/growth rates, so they are adopted as-is (no ×100
 * conversion). Only equity_ratio/operating_income_growth are actually
 * calculated here, from the reported financials (ADR-0009 D3: 実測算出・
 * 近似不採用).
 */
final class UsFundamentalIndicatorMapper
{
    /**
     * @param  array<string, mixed>  $metrics  Finnhub `stock/metric` response's "metric" payload (empty array when unavailable).
     * @param  array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>  $reportedFinancials  Descending (latest-first) reported financials.
     * @return array{per: float|null, pbr: float|null, roe: float|null, revenue_growth: float|null, operating_income_growth: float|null, equity_ratio: float|null, dividend_yield: float|null, dividend_payout_ratio: float|null, eps_growth: float|null, peg_ratio: float|null}
     */
    public function map(array $metrics, array $reportedFinancials): array
    {
        return [
            'per' => $metrics['peTTM'] ?? null,
            'pbr' => $metrics['pbAnnual'] ?? null,
            'roe' => $metrics['roeTTM'] ?? null,
            'revenue_growth' => $metrics['revenueGrowthTTMYoy'] ?? null,
            'operating_income_growth' => $this->calculateOperatingIncomeGrowth($reportedFinancials),
            'equity_ratio' => $this->calculateEquityRatio($reportedFinancials),
            'dividend_yield' => $metrics['dividendYieldIndicatedAnnual'] ?? null,
            'dividend_payout_ratio' => $metrics['payoutRatioTTM'] ?? null,
            'eps_growth' => $metrics['epsGrowthTTMYoy'] ?? null,
            'peg_ratio' => $metrics['pegTTM'] ?? null,
        ];
    }

    /**
     * equity_ratio (%) = total_equity ÷ total_assets × 100, computed from the
     * current period (index 0) only.
     *
     * @param  array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>  $reportedFinancials
     */
    private function calculateEquityRatio(array $reportedFinancials): ?float
    {
        $current = $reportedFinancials[0] ?? null;

        if ($current === null) {
            return null;
        }

        $totalAssets = $current['total_assets'] ?? null;
        $totalEquity = $current['total_equity'] ?? null;

        if ($totalAssets === null || $totalEquity === null || $totalAssets == 0.0) {
            return null;
        }

        return $totalEquity / $totalAssets * 100;
    }

    /**
     * operating_income_growth (%) = (当期 − 前期) ÷ 前期 × 100, comparing
     * index 0 (current) against index 1 (previous).
     *
     * @param  array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>  $reportedFinancials
     */
    private function calculateOperatingIncomeGrowth(array $reportedFinancials): ?float
    {
        if (! isset($reportedFinancials[0], $reportedFinancials[1])) {
            return null;
        }

        $current = $reportedFinancials[0]['operating_income'] ?? null;
        $previous = $reportedFinancials[1]['operating_income'] ?? null;

        if ($current === null || $previous === null || $previous == 0.0) {
            return null;
        }

        return ($current - $previous) / $previous * 100;
    }
}
