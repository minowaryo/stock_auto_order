<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\UsStockPriceClient;
use App\Services\MarketData\UsStockPriceClientInterface;
use App\Services\MarketData\YahooFinanceChartClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UsStockPriceClient — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1: US株
|     価格クライアントをInterface化して app/Services/MarketData/ に配置)
|   - Task description (USティッカーはサフィックスなしでそのまま
|     YahooFinanceChartClient に委譲する)
|
| Neither App\Services\MarketData\UsStockPriceClient nor
| App\Services\MarketData\UsStockPriceClientInterface nor
| App\Services\MarketData\YahooFinanceChartClient exist yet (no file under
| app/Services/MarketData/ at all). The test below is expected to fail with
| a fatal "Class \"App\Services\MarketData\YahooFinanceChartClient\" not
| found" error while constructing the (also not-yet-existing)
| YahooFinanceChartClient collaborator passed into UsStockPriceClient's
| constructor — this is the intentional, expected Red state (same
| convention as tests/Unit/Services/MarketData/YahooFinanceChartClientTest.php
| and JpStockPriceClientTest.php).
|
| This test exercises the real YahooFinanceChartClient collaborator (not a
| mock) with Http::fake(), which asserts the *actual* ticker that reaches
| the outbound HTTP call — consistent with the "no real API is called in
| tests" rule (`.claude/rules/00-global.md` / ADR-0004 §1).
|
| Assumptions made while writing this test (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Constructor signature: `new UsStockPriceClient(YahooFinanceChartClient $client)`
|     (constructor DI, per task description).
|   - `fetchWeeklyPriceHistory(string $symbolCode): array` passes the raw
|     US ticker through unmodified (e.g. "AAPL" → "AAPL", no suffix), pure
|     delegation to YahooFinanceChartClient::fetchWeeklyHistory() with the
|     default $weeks.
|
*/

uses(TestCase::class);

test('USティッカーはサフィックスを付けずそのままYahooFinanceChartClientへ委譲する', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200],
                        'indicators' => [
                            'quote' => [
                                ['close' => [185.5], 'volume' => [45000000]],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new UsStockPriceClient(new YahooFinanceChartClient);

    // Act
    $result = $client->fetchWeeklyPriceHistory('AAPL');

    // Assert: the symbol reaching Yahoo Finance is exactly "AAPL" (no suffix appended)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v8/finance/chart/AAPL?');
    });

    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 185.5, 'volume' => 45000000],
    ]);
});

test('UsStockPriceClientはUsStockPriceClientInterfaceを実装する', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => ['result' => [], 'error' => null],
        ], 200),
    ]);

    // Act
    $client = new UsStockPriceClient(new YahooFinanceChartClient);

    // Assert
    expect($client)->toBeInstanceOf(UsStockPriceClientInterface::class);
});
