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
     * @param  array<int, array{disclosed_date: string, period_type?: string|null, fiscal_year_end?: string|null, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>  $statements  Descending (latest-first) disclosed financial statements.
     * @return array{per: float|null, pbr: float|null, roe: float|null, revenue_growth: float|null, operating_income_growth: float|null, equity_ratio: float|null, operating_margin: float|null, dividend_yield: float|null, dividend_payout_ratio: float|null, eps_growth: float|null, peg_ratio: float|null}
     */
    public function map(array $statements, ?float $currentPrice): array
    {
        $latest = $statements[0] ?? null;

        $per = $this->calculatePer($latest, $currentPrice);
        $revenueGrowth = $this->annualGrowth($statements, 'net_sales');
        $operatingIncomeGrowth = $this->annualGrowth($statements, 'operating_profit');
        $epsGrowth = $this->annualGrowth($statements, 'eps');

        return [
            'per' => $per,
            'pbr' => $this->calculatePbr($latest, $currentPrice),
            'roe' => $this->toPercent($latest['roe'] ?? null),
            'revenue_growth' => $revenueGrowth,
            'operating_income_growth' => $operatingIncomeGrowth,
            'equity_ratio' => $this->toPercent($latest['equity_to_asset_ratio'] ?? null),
            'operating_margin' => $this->calculateOperatingMargin($latest),
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

    /**
     * 営業利益率（%）= 最新期の operating_profit ÷ net_sales × 100
     * （CHG-0012 / ADR-0011 D4、財務健全性フィルタの4条件目）.
     *
     * net_sales が null / 0以下、operating_profit が null なら null
     * （calculatePer() / calculatePbr() と同じガードパターン）。算出結果の
     * 絶対値が 999% を超える場合も null（ADR-0011 D5: 売上ほぼゼロのプレ
     * レベニュー企業。US 側では Finnhub が -243100% を返す例あり。DB に
     * 極端値を残さない）。
     *
     * @param  array<string, float|null>|null  $latest
     */
    private function calculateOperatingMargin(?array $latest): ?float
    {
        $netSales = $latest['net_sales'] ?? null;
        $operatingProfit = $latest['operating_profit'] ?? null;

        if ($netSales === null || $netSales <= 0 || $operatingProfit === null) {
            return null;
        }

        $margin = $operatingProfit / $netSales * 100;

        if (abs($margin) > 999) {
            return null;
        }

        return $margin;
    }

    private function calculatePegRatio(?float $per, ?float $epsGrowth): ?float
    {
        if ($per === null || $epsGrowth === null || $epsGrowth <= 0) {
            return null;
        }

        return $per / $epsGrowth;
    }

    /**
     * Growth rate (%) between the latest full-year (本決算 / FY) statement and
     * the one for the previous fiscal year, for the given field
     * (docs/adr/ADR-0012-growth-rate-fy-comparison.md).
     *
     * J-Quants `/fins/summary` interleaves 1Q/2Q/3Q/FY disclosures and can
     * repeat the same filing under two `disclosed_date`s, so a positional
     * "index 4" comparison mixes a full-year figure with a single quarter.
     * Instead: keep only `period_type === 'FY'` rows, dedupe by
     * `fiscal_year_end` (rows arrive `disclosed_date` descending, so the
     * first occurrence is the most recently disclosed), then compare the
     * two most recent fiscal years. `null` when fewer than two fiscal years
     * are available, or when either value is missing / the prior value is 0.
     *
     * Public so App\Actions\Analysis\FetchExternalMarketDataAction reuses
     * the exact same logic for financial_statements.*_yoy_change instead of
     * keeping a private duplicate (ADR-0012 D3).
     *
     * @param  array<int, array<string, mixed>>  $statements
     */
    public function annualGrowth(array $statements, string $field): ?float
    {
        $annualByFiscalYear = [];

        foreach ($statements as $statement) {
            if (($statement['period_type'] ?? null) !== 'FY') {
                continue;
            }

            $fiscalYearEnd = $statement['fiscal_year_end'] ?? null;

            if ($fiscalYearEnd === null || isset($annualByFiscalYear[$fiscalYearEnd])) {
                continue;
            }

            $annualByFiscalYear[$fiscalYearEnd] = $statement;
        }

        krsort($annualByFiscalYear); // most recent fiscal year first
        $annual = array_values($annualByFiscalYear);

        if (! isset($annual[0], $annual[1])) {
            return null;
        }

        $latestValue = $annual[0][$field] ?? null;
        $pastValue = $annual[1][$field] ?? null;

        if ($latestValue === null || $pastValue === null || $pastValue == 0.0) {
            return null;
        }

        return ($latestValue - $pastValue) / $pastValue * 100;
    }

    /**
     * Simple average of the most recent `$periods` consecutive YoY growth
     * rates (%) for the given field, using the same FY-only filter +
     * fiscal_year_end dedup + descending sort as annualGrowth()
     * (docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md D2).
     *
     * Requires at least `$periods` + 1 FY rows (to form `$periods`
     * consecutive comparisons); otherwise null. If any of the `$periods`
     * comparisons is unavailable (either side null, or the past side 0),
     * the whole method returns null (all-or-nothing, same fail-safe
     * pattern as annualGrowth()).
     *
     * @param  array<int, array<string, mixed>>  $statements
     */
    public function averageAnnualGrowth(array $statements, string $field, int $periods = 3): ?float
    {
        $annualByFiscalYear = [];

        foreach ($statements as $statement) {
            if (($statement['period_type'] ?? null) !== 'FY') {
                continue;
            }

            $fiscalYearEnd = $statement['fiscal_year_end'] ?? null;

            if ($fiscalYearEnd === null || isset($annualByFiscalYear[$fiscalYearEnd])) {
                continue;
            }

            $annualByFiscalYear[$fiscalYearEnd] = $statement;
        }

        krsort($annualByFiscalYear); // most recent fiscal year first
        $annual = array_values($annualByFiscalYear);

        if (count($annual) < $periods + 1) {
            return null;
        }

        $growthRates = [];

        for ($i = 0; $i < $periods; $i++) {
            $latestValue = $annual[$i][$field] ?? null;
            $pastValue = $annual[$i + 1][$field] ?? null;

            if ($latestValue === null || $pastValue === null || $pastValue == 0.0) {
                return null;
            }

            $growthRates[] = ($latestValue - $pastValue) / $pastValue * 100;
        }

        return array_sum($growthRates) / count($growthRates);
    }

    private function toPercent(?float $ratio): ?float
    {
        return $ratio === null ? null : $ratio * 100;
    }
}
