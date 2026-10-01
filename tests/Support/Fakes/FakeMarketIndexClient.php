<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\MarketIndexClientInterface;
use RuntimeException;

/**
 * Deterministic Fake for MarketIndexClientInterface used by Feature Tests
 * that exercise App\Actions\Analysis\FetchExternalMarketDataAction — never
 * makes real HTTP calls (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
 * "テストではFake実装に差し替える").
 */
class FakeMarketIndexClient implements MarketIndexClientInterface
{
    /**
     * Every index_name passed to fetchWeeklyHistory(), in call order
     * (including calls that threw).
     *
     * @var array<int, string>
     */
    public array $requestedIndexNames = [];

    /**
     * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $responses  Weekly index history keyed by index_name ('nikkei225'/'sp500'/'sox').
     * @param  array<int, string>  $throwsFor  index_names for which fetchWeeklyHistory() throws a RuntimeException (simulated fetch failure).
     * @param  array<int, string>  $emptyFor  index_names for which fetchWeeklyHistory() returns [] (simulated HTTP error / no data, as YahooFinanceChartClient does).
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $throwsFor = [],
        private readonly array $emptyFor = [],
    ) {}

    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyHistory(string $indexName): array
    {
        $this->requestedIndexNames[] = $indexName;

        if (in_array($indexName, $this->throwsFor, true)) {
            throw new RuntimeException("Fake market index fetch failure for {$indexName}");
        }

        if (in_array($indexName, $this->emptyFor, true)) {
            return [];
        }

        return $this->responses[$indexName] ?? [];
    }
}
