<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\MarketIndexClientInterface;

/**
 * Deterministic Fake for MarketIndexClientInterface used by Feature Tests
 * that exercise App\Actions\Analysis\FetchExternalMarketDataAction — never
 * makes real HTTP calls (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
 * "テストではFake実装に差し替える").
 */
class FakeMarketIndexClient implements MarketIndexClientInterface
{
    /**
     * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $responses  Weekly index history keyed by index_name ('nikkei225'/'sp500').
     */
    public function __construct(
        private readonly array $responses = [],
    ) {}

    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyHistory(string $indexName): array
    {
        return $this->responses[$indexName] ?? [];
    }
}
