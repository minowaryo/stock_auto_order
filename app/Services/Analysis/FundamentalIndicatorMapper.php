<?php

namespace App\Services\Analysis;

/**
 * Maps a chronologically descending (latest-first) sequence of J-Quants
 * `/fins/summary` financial statements plus the current stock price into
 * fundamental indicator values (PER, PBR, ROE, growth rates, etc.).
 *
 * Pure calculation/mapping logic only — no DB/HTTP dependency
 * (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md).
 *
 * J-Quants returns EqAR/ROE/PayoutRatioAnn as 0〜1 ratios (not percent),
 * so this class is responsible for the ×100 conversion
 * (docs/ai-context/known-pitfalls.md).
 */
final class FundamentalIndicatorMapper
{
    /**
     * @param  array<int, array{disclosed_date: string, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>  $statements  Descending (latest-first) disclosed financial statements.
     * @return array{per: float|null, pbr: float|null, roe: float|null, revenue_growth: float|null, operating_income_growth: float|null, equity_ratio: float|null, dividend_yield: float|null, dividend_payout_ratio: float|null, eps_growth: float|null, peg_ratio: float|null}
     */
    public function map(array $statements, ?float $currentPrice): array
    {
        $latest = $statements[0] ?? null;

        $per = $this->calculatePer($latest, $currentPrice);
        $revenueGrowth = $this->calculateGrowth($statements, 'net_sales');
        $operatingIncomeGrowth = $this->calculateGrowth($statements, 'operating_profit');
        $epsGrowth = $this->calculateGrowth($statements, 'eps');

        return [
            'per' => $per,
            'pbr' => $this->calculatePbr($latest, $currentPrice),
            'roe' => $this->toPercent($latest['roe'] ?? null),
            'revenue_growth' => $revenueGrowth,
            'operating_income_growth' => $operatingIncomeGrowth,
            'equity_ratio' => $this->toPercent($latest['equity_to_asset_ratio'] ?? null),
            'dividend_yield' => $this->calculateDividendYield($latest, $currentPrice),
            'dividend_payout_ratio' => $this->toPercent($latest['payout_ratio_annual'] ?? null),
            'eps_growth' => $epsGrowth,
            'peg_ratio' => $this->calculatePegRatio($per, $epsGrowth),
        ];
    }

    /**
     * @param  array<string, float|null>|null  $latest
     */
    private function calculatePer(?array $latest, ?float $currentPrice): ?float
    {
        $eps = $latest['eps'] ?? null;

        if ($currentPrice === null || $eps === null || $eps <= 0) {
            return null;
        }

        return $currentPrice / $eps;
    }

    /**
     * @param  array<string, float|null>|null  $latest
     */
    private function calculatePbr(?array $latest, ?float $currentPrice): ?float
    {
        $bookValuePerShare = $latest['book_value_per_share'] ?? null;

        if ($currentPrice === null || $bookValuePerShare === null || $bookValuePerShare <= 0) {
            return null;
        }

        return $currentPrice / $bookValuePerShare;
    }

    /**
     * @param  array<string, float|null>|null  $latest
     */
    private function calculateDividendYield(?array $latest, ?float $currentPrice): ?float
    {
        $dividendPerShareAnnual = $latest['dividend_per_share_annual'] ?? null;

        if ($dividendPerShareAnnual === null || $currentPrice === null || $currentPrice <= 0) {
            return null;
        }

        return $dividendPerShareAnnual / $currentPrice * 100;
    }

    private function calculatePegRatio(?float $per, ?float $epsGrowth): ?float
    {
        if ($per === null || $epsGrowth === null || $epsGrowth <= 0) {
            return null;
        }

        return $per / $epsGrowth;
    }

    /**
     * Growth rate (%) between the latest statement (index 0) and the
     * statement 4 periods before (index 4), for the given field.
     *
     * @param  array<int, array<string, float|null>>  $statements
     */
    private function calculateGrowth(array $statements, string $field): ?float
    {
        if (! isset($statements[0], $statements[4])) {
            return null;
        }

        $latestValue = $statements[0][$field] ?? null;
        $pastValue = $statements[4][$field] ?? null;

        if ($latestValue === null || $pastValue === null || $pastValue == 0.0) {
            return null;
        }

        return ($latestValue - $pastValue) / $pastValue * 100;
    }

    private function toPercent(?float $ratio): ?float
    {
        return $ratio === null ? null : $ratio * 100;
    }
}
