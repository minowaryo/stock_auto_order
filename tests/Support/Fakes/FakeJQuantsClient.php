<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\JQuantsClientInterface;

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
     */
    public function __construct(
        private readonly array $sectorResponses = [],
        private readonly array $statementsResponses = [],
    ) {}

    /**
     * @return array{code: string, name: string}|null
     */
    public function fetchSectorInfo(string $symbolCode): ?array
    {
        return $this->sectorResponses[$symbolCode] ?? null;
    }

    /**
     * @return array<int, array{disclosed_date: string, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>
     */
    public function fetchStatements(string $symbolCode, int $periods = 5): array
    {
        return $this->statementsResponses[$symbolCode] ?? [];
    }
}
