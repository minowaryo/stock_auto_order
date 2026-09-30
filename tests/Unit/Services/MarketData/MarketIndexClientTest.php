<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\MarketIndexClient;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\YahooFinanceChartClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| MarketIndexClient — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§4: Phase1
|     で market_indicator_snapshots の取得・保存ロジックを index_name が
|     nikkei225/sp500 の2件分のみ先行実装する。他3件〔us10y/vix/usdjpy〕は
|     UC-007〔Phase2〕着手時にあわせて実装)
|   - docs/architecture/data-model.md (`market_indicator_snapshots.index_name`
|     enum: nikkei225, sp500, us10y, vix, usdjpy)
|   - Task description (index_name → Yahoo symbol マッピング。未対応の
|     index_name は InvalidArgumentException)
|
| Neither App\Services\MarketData\MarketIndexClient nor
| App\Services\MarketData\MarketIndexClientInterface nor
| App\Services\MarketData\YahooFinanceChartClient exist yet (no file under
| app/Services/MarketData/ at all). Every test below is expected to fail
| with a fatal "Class \"App\Services\MarketData\YahooFinanceChartClient\"
| not found" error while constructing the (also not-yet-existing)
| YahooFinanceChartClient collaborator passed into MarketIndexClient's
| constructor — this is the intentional, expected Red state (same
| convention as the other MarketData Red-phase tests in this directory).
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Constructor signature: `new MarketIndexClient(YahooFinanceChartClient $client)`
|     (constructor DI, per task description).
|   - `fetchWeeklyHistory(string $indexName): array` maps 'nikkei225' →
|     Yahoo symbol '^N225' and 'sp500' → Yahoo symbol '^GSPC', then
|     delegates to YahooFinanceChartClient::fetchWeeklyHistory() with the
|     default $weeks (pure delegation of the return value).
|   - Any other `$indexName` (including the three Phase2 values documented
|     in data-model.md: 'us10y', 'vix', 'usdjpy') throws
|     InvalidArgumentException *before* any HTTP call is attempted (no
|     Http::fake() stub is registered for that assertion, so an
|     unintentional real HTTP call would additionally be caught by
|     PendingRequest's "attempted to send a real HTTP request" guard under
|     Http::fake()).
|   - The URL assertions below tolerate either the literal `^` or its
|     percent-encoded form `%5E`, since which one Laravel's HTTP client
|     produces on the wire is an implementation detail not specified by the
|     task description.
|
*/

uses(TestCase::class);

test('nikkei225はYahooシンボル^N225にマッピングされてYahooFinanceChartClientへ委譲される', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200],
                        'indicators' => [
                            'quote' => [
                                ['close' => [33000.0], 'volume' => [0]],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new MarketIndexClient(new YahooFinanceChartClient);

    // Act
    $result = $client->fetchWeeklyHistory('nikkei225');

    // Assert
    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, '/v8/finance/chart/%5EN225?')
            || str_contains($url, '/v8/finance/chart/^N225?');
    });

    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 33000.0, 'volume' => 0],
    ]);
});

test('sp500はYahooシンボル^GSPCにマッピングされてYahooFinanceChartClientへ委譲される', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200],
                        'indicators' => [
                            'quote' => [
                                ['close' => [4700.0], 'volume' => [0]],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new MarketIndexClient(new YahooFinanceChartClient);

    // Act
    $result = $client->fetchWeeklyHistory('sp500');

    // Assert
    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, '/v8/finance/chart/%5EGSPC?')
            || str_contains($url, '/v8/finance/chart/^GSPC?');
    });

    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 4700.0, 'volume' => 0],
    ]);
});

test('未対応のindex_name（Phase2実装分のvix）を渡すとInvalidArgumentExceptionが投げられる', function () {
    // Arrange
    $client = new MarketIndexClient(new YahooFinanceChartClient);

    // Act / Assert
    expect(fn () => $client->fetchWeeklyHistory('vix'))
        ->toThrow(InvalidArgumentException::class);
});

test('MarketIndexClientはMarketIndexClientInterfaceを実装する', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => ['result' => [], 'error' => null],
        ], 200),
    ]);

    // Act
    $client = new MarketIndexClient(new YahooFinanceChartClient);

    // Assert
    expect($client)->toBeInstanceOf(MarketIndexClientInterface::class);
});

/*
|--------------------------------------------------------------------------
| UC-015 / ADR-0019 D2 (CHG-0026 Cycle1, Red phase): SOX指数の追加
|--------------------------------------------------------------------------
|
| 'sox' → Yahoo symbol '^SOX', delegated with the default 104 weeks.
| Expected Red: InvalidArgumentException "Unsupported index_name: sox".
|
*/

test('UC-015: soxはYahooシンボル^SOXにマッピングされ、104週分でYahooFinanceChartClientへ委譲される', function () {
    // Arrange: 106 weekly bars (Mon 2024-01-01 onwards), all with volume 0 like ^SOX
    $start = 1704067200; // 2024-01-01 00:00:00 UTC (Monday)
    $timestamps = array_map(fn (int $i) => $start + $i * 7 * 86400, range(0, 105));
    $closes = array_map(fn (int $i) => 4000.0 + $i * 10.0, range(0, 105));

    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => $timestamps,
                        'indicators' => [
                            'quote' => [
                                ['close' => $closes, 'volume' => array_fill(0, 106, 0)],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new MarketIndexClient(new YahooFinanceChartClient);

    // Act
    $result = $client->fetchWeeklyHistory('sox');

    // Assert: requested symbol
    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, '/v8/finance/chart/%5ESOX?')
            || str_contains($url, '/v8/finance/chart/^SOX?');
    });

    // Assert: sliced to the latest 104 weeks (default weeks), rows are kept despite volume 0
    expect($result)->toHaveCount(104);
    expect($result[0])->toBe(['date' => '2024-01-15', 'close' => 4020.0, 'volume' => 0]);
    expect($result[103]['close'])->toBe(5050.0);
});

test('UC-015: sox追加後もnikkei225/sp500/sox以外（vix）はInvalidArgumentExceptionが投げられる', function () {
    // Arrange
    Http::fake();
    $client = new MarketIndexClient(new YahooFinanceChartClient);

    // Act / Assert
    expect(fn () => $client->fetchWeeklyHistory('vix'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});
