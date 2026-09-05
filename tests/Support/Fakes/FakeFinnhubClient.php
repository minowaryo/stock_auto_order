<?php

namespace Tests\Support\Fakes;

use App\Services\MarketData\FinnhubClientInterface;
use RuntimeException;

/**
 * Deterministic Fake for FinnhubClientInterface used by Feature Tests that
 * exercise App\Actions\Analysis\FetchExternalMarketDataAction — never makes
 * real HTTP calls (docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md, same
 * "テストではFake実装に差し替える" convention as
 * tests/Support/Fakes/FakeJQuantsClient.php).
 *
 * NOTE (Red phase): App\Services\MarketData\FinnhubClientInterface does not
 * exist yet, so this file itself fails to load ("Interface ... not found")
 * the moment anything does `new FakeFinnhubClient(...)` — this is the
 * intentional Red trigger for every test that wires this Fake in, exactly
 * like FakeFinnhubClient's JQuantsClientInterface-implementing sibling would
 * have failed before JQuantsClientInterface existed.
 */
class FakeFinnhubClient implements FinnhubClientInterface
{
    /**
     * @param  array<string, array<string, mixed>|null>  $metricsResponses  Keyed by symbol_code. A missing key or an explicit null value both resolve to null (matches FinnhubClientInterface::fetchMetrics()'s "not found" contract).
     * @param  array<string, array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>>  $reportedFinancialsResponses  Keyed by symbol_code.
     * @param  array<int, string>  $throwsForMetrics  symbol_codes for which fetchMetrics() should raise an exception instead of returning data (used to test that a per-holding failure inside the second, currently-unprotected indicator/signal loop does not abort the rest of the batch — same convention as FakeJQuantsClient's $throwsForStatements).
     * @param  array<int, string>  $throwsForReportedFinancials  symbol_codes for which fetchReportedFinancials() should raise an exception instead of returning data.
     */
    public function __construct(
        private readonly array $metricsResponses = [],
        private readonly array $reportedFinancialsResponses = [],
        private readonly array $throwsForMetrics = [],
        private readonly array $throwsForReportedFinancials = [],
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fetchMetrics(string $symbolCode): ?array
    {
        if (in_array($symbolCode, $this->throwsForMetrics, true)) {
            throw new RuntimeException("FakeFinnhubClient: simulated fetchMetrics failure for symbol_code={$symbolCode}");
        }

        return $this->metricsResponses[$symbolCode] ?? null;
    }

    /**
     * @return array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>
     */
    public function fetchReportedFinancials(string $symbolCode, int $periods = 2): array
    {
        if (in_array($symbolCode, $this->throwsForReportedFinancials, true)) {
            throw new RuntimeException("FakeFinnhubClient: simulated fetchReportedFinancials failure for symbol_code={$symbolCode}");
        }

        return $this->reportedFinancialsResponses[$symbolCode] ?? [];
    }
}
