<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\YahooFinanceChartClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| YahooFinanceChartClient — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1: 外部
|     データ取得はIlluminate\Http\Clientで直接呼び出す方式。J-Quants/
|     Yahoo Financeクライアントはapp/Services/MarketData/にInterface化)
|   - docs/architecture/data-model.md (`technical_indicators`/
|     `market_indicator_snapshots` の入力となる週次価格系列)
|   - Task description (Yahoo Finance非公式chart API v8, range=2y&interval=1wk)
|
| App\Services\MarketData\YahooFinanceChartClient does not exist yet (no
| file under app/Services/MarketData/ at all). Every test below is expected
| to fail with a fatal "Class \"App\Services\MarketData\
| YahooFinanceChartClient\" not found" error at the `new
| YahooFinanceChartClient()` line inside the Act step — this is the
| intentional, expected Red state (same convention as
| tests/Unit/Services/Analysis/TechnicalIndicatorCalculatorTest.php).
|
| This client makes an outbound HTTP call via
| Illuminate\Support\Facades\Http, which requires the Laravel container to
| be bootstrapped for Http::fake() to resolve a facade root. Unlike the
| pure-calculation TechnicalIndicatorCalculatorTest, this file therefore
| binds to Tests\TestCase (no RefreshDatabase trait — this class does not
| touch the DB) via `uses(TestCase::class)` below, while still living under
| tests/Unit/ per `.claude/rules/30-testing.md` priority 2 (no HTTP calls
| are made to the real Yahoo Finance API; Http::fake() intercepts them).
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoint: GET https://query1.finance.yahoo.com/v8/finance/chart/{symbol}?range=2y&interval=1wk
|   - Response shape: chart.result[0].timestamp (UNIX epoch seconds,
|     ascending) and chart.result[0].indicators.quote[0].close /
|     .volume (parallel arrays, same length as timestamp; elements may be
|     null for a missing week).
|   - `date` in the return value is Y-m-d derived from the epoch second
|     using the app's default timezone, which is 'UTC' per config/app.php
|     (timestamps below are all UTC midnight, so no additional TZ-offset
|     handling is assumed necessary to reproduce the expected dates).
|   - A week is excluded from the result entirely if close OR volume is
|     null at that index (not coerced to 0/null in the output array).
|   - Non-200 HTTP status, or a missing/empty chart.result, returns []
|     without throwing (no exception-based assertions in this file).
|
*/

uses(TestCase::class);

test('週次価格履歴を取得し日付昇順でclose/volumeを返す', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200, 1704672000, 1705276800],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [100.0, 101.5, 102.25],
                                    'volume' => [1000000, 1100000, 1200000],
                                ],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $result = $client->fetchWeeklyHistory('7203.T', 104);

    // Assert
    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 100.0, 'volume' => 1000000],
        ['date' => '2024-01-08', 'close' => 101.5, 'volume' => 1100000],
        ['date' => '2024-01-15', 'close' => 102.25, 'volume' => 1200000],
    ]);
});

test('closeまたはvolumeがnullの週は結果から除外される', function () {
    // Arrange: index1 has close=null, index2 has volume=null
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200, 1704672000, 1705276800, 1705881600],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [100.0, null, 102.0, 103.0],
                                    'volume' => [1000, 2000, null, 4000],
                                ],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $result = $client->fetchWeeklyHistory('7203.T', 104);

    // Assert: only index0 and index3 survive
    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 100.0, 'volume' => 1000],
        ['date' => '2024-01-22', 'close' => 103.0, 'volume' => 4000],
    ]);
});

test('取得件数が指定週数を超える場合は直近N件のみに絞られる', function () {
    // Arrange: 5 weeks of data, request only the most recent 2
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200, 1704672000, 1705276800, 1705881600, 1706486400],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [100.0, 101.0, 102.0, 103.0, 104.0],
                                    'volume' => [1000, 1100, 1200, 1300, 1400],
                                ],
                            ],
                        ],
                    ],
                ],
                'error' => null,
            ],
        ], 200),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $result = $client->fetchWeeklyHistory('7203.T', 2);

    // Assert: last 2 weeks only, still ascending
    expect($result)->toBe([
        ['date' => '2024-01-22', 'close' => 103.0, 'volume' => 1300],
        ['date' => '2024-01-29', 'close' => 104.0, 'volume' => 1400],
    ]);
});

test('リクエストURLにシンボル・range=2y・interval=1wkが含まれる', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => ['result' => [], 'error' => null],
        ], 200),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $client->fetchWeeklyHistory('AAPL', 104);

    // Assert
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'query1.finance.yahoo.com/v8/finance/chart/AAPL')
            && str_contains($request->url(), 'range=2y')
            && str_contains($request->url(), 'interval=1wk');
    });
});

test('HTTPステータスが200以外の場合は例外を投げず空配列を返す', function () {
    // Arrange
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $result = $client->fetchWeeklyHistory('INVALID', 104);

    // Assert
    expect($result)->toBe([]);
});

test('chart.resultが空配列の場合は例外を投げず空配列を返す', function () {
    // Arrange: Yahoo returns an empty result array for a nonexistent symbol
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => ['result' => [], 'error' => null],
        ], 200),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $result = $client->fetchWeeklyHistory('NOTFOUND', 104);

    // Assert
    expect($result)->toBe([]);
});

test('chart.resultがnull（存在しない）場合は例外を投げず空配列を返す', function () {
    // Arrange: Yahoo returns a chart.error payload instead of chart.result
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => null,
                'error' => ['code' => 'Not Found', 'description' => 'No data found, symbol may be delisted'],
            ],
        ], 200),
    ]);

    $client = new YahooFinanceChartClient;

    // Act
    $result = $client->fetchWeeklyHistory('DELISTED', 104);

    // Assert
    expect($result)->toBe([]);
});
