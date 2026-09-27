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

/*
|--------------------------------------------------------------------------
| Additional cases — 未確定週（in-progress week）の除外
|--------------------------------------------------------------------------
|
| Discovered against the real Yahoo Finance chart API (verified with
| 7203.T and the Nikkei 225 index): when the API is called outside of
| market hours / mid-week, the LAST element of the weekly series can be a
| placeholder for the still-in-progress current week, characterized by
| volume === 0 AND close identical to the previous (confirmed) week's
| close. If left in the series, this placeholder is mistaken for the
| latest confirmed week, corrupting change_rate (falsely shows 0%) and any
| "most recent value" indicator calculation (RSI/MACD) that reads the last
| element.
|
| YahooFinanceChartClient::fetchWeeklyHistory() does not yet implement
| this exclusion (see current implementation: it only drops weeks where
| close or volume is null — a volume=0 trailing week with a
| duplicate close is NOT null-filtered and passes through unchanged).
| The two tests below are expected to fail (Red) against the current
| implementation.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Only the trailing (last) element of the series is a candidate for
|     this exclusion; only volume === 0 AND close === previous week's
|     close together trigger removal (guards against dropping a
|     legitimate quiet-trading week where volume genuinely happens to be
|     low/zero but the price still moved).
|   - The `weeks` slicing (-N) is expected to apply after this exclusion,
|     so a placeholder week must not count against the requested window.
|
*/

test('末尾が出来高0・前週と同じ終値の未確定週の場合、その週は結果から除外される', function () {
    // Arrange: 3 weeks; the last week is an unconfirmed in-progress week
    // placeholder (volume=0, close identical to the previous week's close).
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1704067200, 1704672000, 1705276800],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [100.0, 101.5, 101.5],
                                    'volume' => [1000000, 1100000, 0],
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

    // Assert: the trailing placeholder week (2024-01-15) is excluded
    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 100.0, 'volume' => 1000000],
        ['date' => '2024-01-08', 'close' => 101.5, 'volume' => 1100000],
    ]);
});

test('volumeが0でもcloseが前週と異なる場合は除外しない', function () {
    // Arrange: 3 weeks; the last week has volume=0 but its close differs
    // from the previous week's close, so it must be treated as a
    // legitimate (if illiquid) confirmed week, not a placeholder.
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
                                    'volume' => [1000000, 1100000, 0],
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

    // Assert: all three weeks are kept, including the volume=0 last week
    expect($result)->toBe([
        ['date' => '2024-01-01', 'close' => 100.0, 'volume' => 1000000],
        ['date' => '2024-01-08', 'close' => 101.5, 'volume' => 1100000],
        ['date' => '2024-01-15', 'close' => 102.25, 'volume' => 0],
    ]);
});

/*
|--------------------------------------------------------------------------
| ADR-0017 D5 — 分割調整の前提を固定する回帰テスト（CHG-0020 Cycle1）
|--------------------------------------------------------------------------
|
| Source of truth: docs/adr/ADR-0017-signal-outcome-tracking.md D5,
| docs/architecture/data-model.md `weekly_prices.close`.
|
| Premise pinned here (measured 2026-09-27 against NTT 9432.T, which did a
| 25:1 split around 2023-06-25):
|   - indicators.quote[0].close is ALREADY split-adjusted retroactively
|     (pre-split weekly closes come back at ~161 JPY, continuous with the
|     post-split series) but NOT dividend-adjusted.
|   - indicators.adjclose[0].adjclose is dividend-adjusted and differs from
|     close by ~9% for NTT — it must NOT be used (index series are price
|     indices without dividends, so excess returns must compare price vs
|     price).
| weekly_prices stores this series for UC-014 excess-return calculation, so
| if a future change switched to adjclose (or Yahoo stopped retro-adjusting
| splits) this test must fail.
|
| This test pins an EXISTING assumption of YahooFinanceChartClient and is
| expected to already pass (not a Red test in the usual sense).
|
*/

test('分割前後の週足はquote.close（分割遡及調整済み・配当未調整）の連続した系列で返り、adjcloseは使われない（NTT 9432相当）', function () {
    // Arrange: JP-style bars stamped Sunday 15:00 UTC around the 2023-06-25 25:1 split
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'meta' => ['symbol' => '9432.T', 'currency' => 'JPY'],
                        'timestamp' => [1686495600, 1687100400, 1687705200, 1688310000],
                        'events' => [
                            'splits' => [
                                '1687705200' => [
                                    'date' => 1687705200,
                                    'numerator' => 25.0,
                                    'denominator' => 1.0,
                                    'splitRatio' => '25:1',
                                ],
                            ],
                        ],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [160.9, 161.8, 163.2, 164.0],
                                    'volume' => [120000000, 130000000, 250000000, 180000000],
                                ],
                            ],
                            'adjclose' => [
                                [
                                    'adjclose' => [147.1, 147.9, 149.2, 150.0],
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
    $result = $client->fetchWeeklyHistory('9432.T', 104);

    // Assert: quote.close as-is — continuous across the split (no ~25x jump)
    expect($result)->toBe([
        ['date' => '2023-06-11', 'close' => 160.9, 'volume' => 120000000],
        ['date' => '2023-06-18', 'close' => 161.8, 'volume' => 130000000],
        ['date' => '2023-06-25', 'close' => 163.2, 'volume' => 250000000],
        ['date' => '2023-07-02', 'close' => 164.0, 'volume' => 180000000],
    ]);

    $closes = array_column($result, 'close');
    for ($i = 1; $i < count($closes); $i++) {
        expect(abs($closes[$i] / $closes[$i - 1] - 1))->toBeLessThan(0.5);
    }
});

/*
|--------------------------------------------------------------------------
| CHG-0022 — 同一週に属する末尾バー（最終取引日バー）の除外（回帰テスト）
|--------------------------------------------------------------------------
|
| Measured 2026-09-27 against the real Yahoo Finance chart API
| (range=...&interval=1wk): besides the volume=0 in-progress placeholder
| handled above, Yahoo also appends a trailing bar for the LAST TRADING DAY
| with NONZERO volume, stamped with a date inside the same week as the
| previous (proper weekly) bar:
|   - 7203.T: Sun 2026-09-20 15:00 UTC (close 2989.5, vol 54901300)
|             then Fri 2026-09-25 06:30 UTC (close 2989.5, vol 21084000)
|   - AAPL:   Mon 2026-09-21 04:00 UTC (close 341.07, vol 162053000)
|             then Fri 2026-09-25 20:00 UTC (close 341.07, vol 29649787)
|   - ^N225:  trailing Fri 2026-09-25 06:45 UTC with volume 0 (already
|             handled by the existing placeholder rule)
| Keeping that trailing bar makes callers (TechnicalIndicatorCalculator:
| RSI, 13-week returns, MA, ...) count the last week twice.
|
| Expected contract (CHG-0022):
|   - "Week" of a row = Monday of the ISO week containing
|     (gmdate('Y-m-d', timestamp) + 1 day). This maps JP/^N225 weekly bars
|     (stamped Sunday 15:00 UTC) and US weekly bars (Monday 04:00 UTC) to
|     the same Monday week start as their trailing Friday bars.
|   - If the last row falls in the same week as the previous row, the last
|     row is dropped and the earlier (proper weekly) bar is kept.
|   - A last row that starts a genuinely new week is kept.
|   - The existing volume=0 placeholder rule keeps working.
|   - The $weeks slicing applies after the drop.
|
| Tests 1/2/3/6 below are expected to fail (Red) against the current
| implementation, which only drops a trailing row when volume === 0 AND
| close equals the previous close. Tests 4/5 are guards that already pass.
|
*/

test('日本株形式（日曜15:00UTC刻印）の週足末尾に同一週の出来高ありの最終取引日バーが付く場合、その末尾バーは除外され週足バーが最後に残る', function () {
    // Arrange: 7203.T-like series; last element is the trailing Friday bar
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        // Sun 09-06 15:00, Sun 09-13 15:00, Sun 09-20 15:00, Fri 09-25 06:30 (UTC)
                        'timestamp' => [1788706800, 1789311600, 1789916400, 1790317800],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [2900.0, 2950.0, 2989.5, 2989.5],
                                    'volume' => [50000000, 52000000, 54901300, 21084000],
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

    // Assert: the trailing Friday bar is dropped; the Sunday-stamped weekly bar (original volume) is last
    expect($result)->toBe([
        ['date' => '2026-09-06', 'close' => 2900.0, 'volume' => 50000000],
        ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
        ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
    ]);
});

test('米国株形式（月曜04:00UTC刻印）の週足末尾に同一週の出来高ありの金曜20:00UTCバーが付く場合、その末尾バーは除外される', function () {
    // Arrange: AAPL-like series
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        // Mon 09-07 04:00, Mon 09-14 04:00, Mon 09-21 04:00, Fri 09-25 20:00 (UTC)
                        'timestamp' => [1788753600, 1789358400, 1789963200, 1790366400],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [330.0, 335.5, 341.07, 341.07],
                                    'volume' => [150000000, 155000000, 162053000, 29649787],
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
    $result = $client->fetchWeeklyHistory('AAPL', 104);

    // Assert
    expect($result)->toBe([
        ['date' => '2026-09-07', 'close' => 330.0, 'volume' => 150000000],
        ['date' => '2026-09-14', 'close' => 335.5, 'volume' => 155000000],
        ['date' => '2026-09-21', 'close' => 341.07, 'volume' => 162053000],
    ]);
});

test('同一週の末尾バーの終値が週足バーと異なる場合（週途中の日中バー）でも末尾バーは除外され週足バーが残る', function () {
    // Arrange: trailing Wednesday bar inside the same week as the Sunday-stamped
    // weekly bar, with a DIFFERENT close (e.g. fetched mid-week).
    // The weekly bar is the canonical row for its week; any later row in the
    // same week is a duplicate representation of that week and must not be
    // counted as an additional week, regardless of its close/volume.
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        // Sun 09-13 15:00, Sun 09-20 15:00, Wed 09-23 06:00 (UTC)
                        'timestamp' => [1789311600, 1789916400, 1790143200],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [2950.0, 2989.5, 2975.0],
                                    'volume' => [52000000, 54901300, 12000000],
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
        ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
        ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
    ]);
});

test('末尾が前行の7日後に始まる新しい週（出来高あり）の場合は除外されない', function () {
    // Arrange: JP-style; last row is the next Sunday 15:00 UTC stamp (a genuinely new week)
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        // Sun 09-13 15:00, Sun 09-20 15:00, Sun 09-27 15:00 (UTC)
                        'timestamp' => [1789311600, 1789916400, 1790521200],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [2950.0, 2989.5, 2989.5],
                                    'volume' => [52000000, 54901300, 48000000],
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

    // Assert: all three rows kept (same close as the previous week is legitimate here)
    expect($result)->toBe([
        ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
        ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
        ['date' => '2026-09-27', 'close' => 2989.5, 'volume' => 48000000],
    ]);
});

test('指数（^N225相当）の同一週末尾バーが出来高0の場合も従来通り除外される', function () {
    // Arrange: ^N225-like; trailing Fri 09-25 06:30 UTC bar with volume 0 and same close
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        'timestamp' => [1789311600, 1789916400, 1790317800],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [44000.0, 45000.5, 45000.5],
                                    'volume' => [0, 0, 0],
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
    $result = $client->fetchWeeklyHistory('^N225', 104);

    // Assert
    expect($result)->toBe([
        ['date' => '2026-09-13', 'close' => 44000.0, 'volume' => 0],
        ['date' => '2026-09-20', 'close' => 45000.5, 'volume' => 0],
    ]);
});

test('週数指定がある場合、同一週の末尾バー除外後に直近N週へ絞られ週足バーで終わる', function () {
    // Arrange: 4 proper JP weekly bars + trailing Friday bar in the last week
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response([
            'chart' => [
                'result' => [
                    [
                        // Sun 08-30, 09-06, 09-13, 09-20 15:00 UTC, then Fri 09-25 06:30 UTC
                        'timestamp' => [1788102000, 1788706800, 1789311600, 1789916400, 1790317800],
                        'indicators' => [
                            'quote' => [
                                [
                                    'close' => [2880.0, 2900.0, 2950.0, 2989.5, 2989.5],
                                    'volume' => [49000000, 50000000, 52000000, 54901300, 21084000],
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
    $result = $client->fetchWeeklyHistory('7203.T', 3);

    // Assert: min(3, 4 distinct weeks) = 3 rows, ending with the Sunday-stamped weekly bar
    expect($result)->toBe([
        ['date' => '2026-09-06', 'close' => 2900.0, 'volume' => 50000000],
        ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
        ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
    ]);
});
