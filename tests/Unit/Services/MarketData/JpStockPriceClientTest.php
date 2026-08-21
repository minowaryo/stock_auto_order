<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\JpStockPriceClient;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\YahooFinanceChartClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| JpStockPriceClient — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1: JP株
|     価格クライアントをInterface化して app/Services/MarketData/ に配置)
|   - Task description (JP証券コード → Yahoo symbol は `.T` サフィックス
|     を付与して YahooFinanceChartClient に委譲する)
|
| Neither App\Services\MarketData\JpStockPriceClient nor
| App\Services\MarketData\JpStockPriceClientInterface nor
| App\Services\MarketData\YahooFinanceChartClient exist yet (no file under
| app/Services/MarketData/ at all). The test below is expected to fail with
| a fatal "Class \"App\Services\MarketData\YahooFinanceChartClient\" not
| found" error while constructing the (also not-yet-existing)
| YahooFinanceChartClient collaborator passed into JpStockPriceClient's
| constructor — this is the intentional, expected Red state (same
| convention as tests/Unit/Services/MarketData/YahooFinanceChartClientTest.php).
|
| This test exercises the real YahooFinanceChartClient collaborator (not a
| mock) with Http::fake(), which asserts the *actual* symbol that reaches
| the outbound HTTP call — a stricter contract than mocking the
| collaborator's method call, and consistent with the "no real API is
| called in tests" rule (`.claude/rules/00-global.md` / ADR-0004 §1).
|
| Assumptions made while writing this test (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Constructor signature: `new JpStockPriceClient(YahooFinanceChartClient $client)`
|     (constructor DI, per task description).
|   - `fetchWeeklyPriceHistory(string $symbolCode): array` appends `.T` to
|     the raw JP securities code (e.g. "7203" → "7203.T") and otherwise
|     returns whatever YahooFinanceChartClient::fetchWeeklyHistory() returns
|     unmodified (pure delegation, default $weeks).
|
*/

uses(TestCase::class);

test('JP証券コードに.Tサフィックスを付与してYahooFinanceChartClientへ委譲する', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200],
                        'indicators' => [
                            'quote' => [
                                ['close' => [2500.0], 'volume' => [50000]],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new JpStockPriceClient(new YahooFinanceChartClient);

    // Act
    $result = $client->fetchWeeklyPriceHistory('7203');

    // Assert: the symbol reaching Yahoo Finance is exactly "7203.T"
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v8/finance/chart/7203.T?');
    });

    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 2500.0, 'volume' => 50000],
    ]);
});

test('JpStockPriceClientはJpStockPriceClientInterfaceを実装する', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => ['result' => [], 'error' => null],
        ], 200),
    ]);

    // Act
    $client = new JpStockPriceClient(new YahooFinanceChartClient);

    // Assert
    expect($client)->toBeInstanceOf(JpStockPriceClientInterface::class);
});
