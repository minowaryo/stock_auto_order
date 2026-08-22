<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\JQuantsClientInterface;
use RuntimeException;

/**
 * Deterministic Fake for JQuantsClientInterface used by Feature Tests that
 * exercise App\Actions\Analysis\FetchExternalMarketDataAction — never makes
 * real HTTP calls (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
 * "テストではFake実装に差し替える").
 */
class FakeJQuantsClient implements JQuantsClientInterface
{
    /**
     * @param  array<string, array{code: string, name: string}|null>  $sectorResponses  Keyed by symbol_code.
     * @param  array<string, array<int, array{disclosed_date: string, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>>  $statementsResponses  Keyed by symbol_code.
     * @param  array<int, string>  $throwsForSectorInfo  symbol_codes for which fetchSectorInfo() should raise an exception instead of returning data (used to test that a per-holding failure outside the price-history try/catch does not abort the rest of the batch).
     * @param  array<int, string>  $throwsForStatements  symbol_codes for which fetchStatements() should raise an exception instead of returning data (used to test that a per-holding failure inside the second, currently-unprotected indicator/signal loop does not abort the rest of the batch).
     */
    public function __construct(
        private readonly array $sectorResponses = [],
        private readonly array $statementsResponses = [],
        private readonly array $throwsForSectorInfo = [],
        private readonly array $throwsForStatements = [],
    ) {}

    /**
     * @return array{code: string, name: string}|null
     */
    public function fetchSectorInfo(string $symbolCode): ?array
    {
        if (in_array($symbolCode, $this->throwsForSectorInfo, true)) {
            throw new RuntimeException("FakeJQuantsClient: simulated fetchSectorInfo failure for symbol_code={$symbolCode}");
        }

        return $this->sectorResponses[$symbolCode] ?? null;
    }

    /**
     * @return array<int, array{disclosed_date: string, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>
     */
    public function fetchStatements(string $symbolCode, int $periods = 5): array
    {
        if (in_array($symbolCode, $this->throwsForStatements, true)) {
            throw new RuntimeException("FakeJQuantsClient: simulated fetchStatements failure for symbol_code={$symbolCode}");
        }

        return $this->statementsResponses[$symbolCode] ?? [];
    }
}
