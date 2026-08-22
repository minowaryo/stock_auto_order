<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\JpStockPriceClientInterface;
use RuntimeException;

/**
 * Deterministic Fake for JpStockPriceClientInterface used by Feature Tests
 * that exercise App\Actions\Analysis\FetchExternalMarketDataAction — never
 * makes real HTTP calls (docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
 * "テストではFake実装に差し替える").
 */
class FakeJpStockPriceClient implements JpStockPriceClientInterface
{
    /**
     * @param  array<string, array<int, array{date: string, close: float, volume: int}>>  $responses  Weekly price history keyed by symbol_code.
     * @param  array<int, string>  $throwsFor  symbol_codes for which fetchWeeklyPriceHistory() should raise an exception instead of returning data (used to test the "1銘柄の失敗が他銘柄を止めない" business rule).
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $throwsFor = [],
    ) {}

    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyPriceHistory(string $symbolCode): array
    {
        if (in_array($symbolCode, $this->throwsFor, true)) {
            throw new RuntimeException("FakeJpStockPriceClient: simulated fetch failure for symbol_code={$symbolCode}");
        }

        return $this->responses[$symbolCode] ?? [];
    }
}
