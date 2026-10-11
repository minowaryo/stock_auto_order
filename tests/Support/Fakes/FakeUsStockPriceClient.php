<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\PriceHistory;
use App\Services\MarketData\UsStockPriceClientInterface;
use RuntimeException;

/**
 * Deterministic Fake for UsStockPriceClientInterface used by Feature Tests
 * that exercise App\Actions\Analysis\FetchExternalMarketDataAction — never
 * makes real HTTP calls (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
 * "テストではFake実装に差し替える").
 */
class FakeUsStockPriceClient implements UsStockPriceClientInterface
{
    /**
     * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $responses  Weekly price history keyed by symbol_code.
     * @param  array<int, string>  $throwsFor  symbol_codes for which fetchWeeklyPriceHistory() should raise an exception instead of returning data (used to test the "1銘柄の失敗が他銘柄を止めない" business rule).
     * @param  array<string, list<array{date: string, numerator: int, denominator: int}>>  $splits  Stock splits keyed by symbol_code, returned by fetchWeeklyPriceHistoryWithSplits() (CHG-0033 Cycle 6e).
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $throwsFor = [],
        private readonly array $splits = [],
    ) {}

    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyPriceHistory(string $symbolCode): array
    {
        if (in_array($symbolCode, $this->throwsFor, true)) {
            throw new RuntimeException("FakeUsStockPriceClient: simulated fetch failure for symbol_code={$symbolCode}");
        }

        return $this->responses[$symbolCode] ?? [];
    }

    /**
     * Same canned rows as fetchWeeklyPriceHistory(), wrapped with the splits.
     */
    public function fetchWeeklyPriceHistoryWithSplits(string $symbolCode): PriceHistory
    {
        $rows = $this->fetchWeeklyPriceHistory($symbolCode);

        return $rows === []
            ? new PriceHistory(PriceHistory::EMPTY)
            : new PriceHistory(PriceHistory::OK, $rows, $this->splits[$symbolCode] ?? []);
    }
}
