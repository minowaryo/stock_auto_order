<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\FinnhubClient;
use App\Services\MarketData\FinnhubClientInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| FinnhubClient — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md (D1〜D5: Finnhub
|     as the US-stock fundamentals data source, `stock/metric`/
|     `stock/financials-reported` endpoints, self-throttling on
|     X-Ratelimit-Remaining, 429 retry-without-exception)
|   - docs/architecture/data-model.md (`fundamental_indicators` — market-
|     agnostic nullable columns this client's output eventually feeds)
|   - Task description for this Red-phase generation (exact interface
|     contract: `fetchMetrics(string $symbolCode): ?array` returns the
|     "metric" key verbatim; `fetchReportedFinancials(string $symbolCode,
|     int $periods = 2): array` extracts total_assets/total_equity/
|     operating_income per fiscal period from `report.bs`/`report.ic`
|     XBRL concept arrays)
|
| App\Services\MarketData\FinnhubClient and
| App\Services\MarketData\FinnhubClientInterface do not exist yet (no file
| under app/Services/MarketData/ named FinnhubClient.php /
| FinnhubClientInterface.php — verified via `find` before writing this
| file). Every test below is expected to fail with a fatal "Class/Interface
| not found" error — either at the `new FinnhubClient(...)` line inside the
| Act step, or at the `FinnhubClientInterface::class` reference used for the
| `implements` assertion — this is the intentional, expected Red state (same
| convention as tests/Unit/Services/MarketData/JQuantsClientTest.php).
|
| No RefreshDatabase — this client makes outbound HTTP calls via
| Illuminate\Support\Facades\Http (Http::fake() requires the Laravel
| container to be bootstrapped) but does not touch the DB, matching
| JQuantsClientTest's `uses(TestCase::class)`-only setup.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred, per the task description's explicit invitation to do so):
|   - Constructor signature: `new FinnhubClient(?callable $sleeper = null)`.
|     The API key is read internally from config('services.finnhub.key')
|     (mirrors JQuantsClient's config('services.jquants.api_key') pattern —
|     no constructor arg for the key itself). $sleeper is an injectable
|     callable invoked as `$sleeper(int $microseconds)` in place of a real
|     `usleep()` call, so tests can spy on "was a wait attempted" without
|     actually waiting. When $sleeper is omitted, the real client is assumed
|     to fall back to an actual `usleep()`-based wait (not exercised here,
|     since exercising the true default would slow this test suite down).
|   - GET https://finnhub.io/api/v1/stock/metric carries `symbol`, `metric=all`,
|     and `token` as query parameters (per the task description's literal
|     URL shape); GET https://finnhub.io/api/v1/stock/financials-reported
|     carries `symbol`, `freq=annual`, and `token`.
|   - The self-throttling threshold on the `X-Ratelimit-Remaining` response
|     header is left unpinned to a specific number (task description: "具体的な
|     閾値は実装時に確定してよい"). These tests instead use a response header
|     value (`1`) that is unambiguously "low" under any reasonable threshold
|     choice, and a value (`1000`) that is unambiguously "high", to avoid
|     coupling to a specific implementation constant.
|   - 429 retries: the exact retry cap/backoff schedule is left to the
|     implementation. These tests only assert the two externally-visible
|     contract points explicitly required by the task description: (a) a
|     429 followed by a 200 eventually returns the successful payload
|     without throwing, and (b) 429 responses that never succeed eventually
|     terminate (no infinite loop) and return null / [] without throwing,
|     rather than raising an exception up to the caller.
|   - Interface method signatures on FinnhubClientInterface mirror the task
|     description exactly: fetchMetrics(string $symbolCode): ?array and
|     fetchReportedFinancials(string $symbolCode, int $periods = 2): array.
|
*/

uses(TestCase::class);

beforeEach(function () {
    config()->set([
        'services.finnhub.key' => 'dummy-finnhub-key',
    ]);
});

/**
 * Builds a `stock/financials-reported`-shaped fixture with 3 annual (10-K)
 * reports, latest-first (Finnhub's own documented ordering, per the task
 * description). Figures are internally self-consistent
 * (total_assets = total_liabilities + total_equity) but are test fixture
 * values, not the exact real AAPL FY2025 figures quoted in the task
 * description for the *mapper's* test (only total_assets/total_equity are
 * pinned to the real AAPL numbers here, to double-check this client's
 * extraction logic against a real, verified data point).
 *
 * - data[0] (2025, latest): total_assets=359241000000, total_equity=73733000000,
 *   operating_income=123216000000.
 * - data[1] (2024): total_assets=352755000000, total_equity=56950000000,
 *   operating_income=114301000000.
 * - data[2] (2023): deliberately MISSING the us-gaap_StockholdersEquity
 *   concept from `bs` and has an EMPTY `ic` array (missing
 *   us-gaap_OperatingIncomeLoss) — exercises "concept not found -> null,
 *   don't throw" (partial-data tolerance, per the task description).
 */
function finnhubReportedFinancialsFixture(): array
{
    return [
        'data' => [
            [
                'year' => 2025,
                'form' => '10-K',
                'report' => [
                    'bs' => [
                        ['concept' => 'us-gaap_Assets', 'value' => 359241000000, 'unit' => 'USD'],
                        ['concept' => 'us-gaap_Liabilities', 'value' => 285508000000, 'unit' => 'USD'],
                        ['concept' => 'us-gaap_StockholdersEquity', 'value' => 73733000000, 'unit' => 'USD'],
                    ],
                    'ic' => [
                        ['concept' => 'us-gaap_OperatingIncomeLoss', 'value' => 123216000000, 'unit' => 'USD'],
                    ],
                ],
            ],
            [
                'year' => 2024,
                'form' => '10-K',
                'report' => [
                    'bs' => [
                        ['concept' => 'us-gaap_Assets', 'value' => 352755000000, 'unit' => 'USD'],
                        ['concept' => 'us-gaap_StockholdersEquity', 'value' => 56950000000, 'unit' => 'USD'],
                    ],
                    'ic' => [
                        ['concept' => 'us-gaap_OperatingIncomeLoss', 'value' => 114301000000, 'unit' => 'USD'],
                    ],
                ],
            ],
            [
                'year' => 2023,
                'form' => '10-K',
                'report' => [
                    'bs' => [
                        ['concept' => 'us-gaap_Assets', 'value' => 352583000000, 'unit' => 'USD'],
                    ],
                    'ic' => [],
                ],
            ],
        ],
    ];
}

// -----------------------------------------------------------------------
// fetchMetrics
// -----------------------------------------------------------------------

test('fetchMetrics呼び出し時にsymbol・metric=all・tokenクエリパラメータが設定値で付与される', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::response(['metric' => ['peTTM' => 37.3169]], 200),
    ]);

    $client = new FinnhubClient;

    $client->fetchMetrics('AAPL');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'finnhub.io/api/v1/stock/metric')
            && str_contains($request->url(), 'symbol=AAPL')
            && str_contains($request->url(), 'metric=all')
            && str_contains($request->url(), 'token=dummy-finnhub-key');
    });
});

test('fetchMetricsはレスポンスの"metric"キー配下をそのままの連想配列で返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::response([
            'metric' => [
                'peTTM' => 37.3169,
                'pbAnnual' => 50.978,
                'roeTTM' => 137.18,
                'revenueGrowthTTMYoy' => 14.24,
                'epsGrowthTTMYoy' => 32.61,
                'dividendYieldIndicatedAnnual' => 0.50534,
                'payoutRatioTTM' => 12.13,
                'pegTTM' => 2.93443,
            ],
            'metricType' => 'all',
            'symbol' => 'AAPL',
        ], 200),
    ]);

    $client = new FinnhubClient;

    $result = $client->fetchMetrics('AAPL');

    expect($result)->toBe([
        'peTTM' => 37.3169,
        'pbAnnual' => 50.978,
        'roeTTM' => 137.18,
        'revenueGrowthTTMYoy' => 14.24,
        'epsGrowthTTMYoy' => 32.61,
        'dividendYieldIndicatedAnnual' => 0.50534,
        'payoutRatioTTM' => 12.13,
        'pegTTM' => 2.93443,
    ]);
});

test('fetchMetricsは"metric"キーが存在しない場合nullを返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::response(['metric' => null, 'metricType' => 'all', 'symbol' => 'UNKNOWN'], 200),
    ]);

    $client = new FinnhubClient;

    $result = $client->fetchMetrics('UNKNOWN');

    expect($result)->toBeNull();
});

test('fetchMetricsは429が返っても例外を投げずリトライし、最終的に200が返ればmetricをそのまま返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::sequence()
            ->push(['error' => 'API limit reached'], 429)
            ->push(['metric' => ['peTTM' => 37.3169]], 200),
    ]);

    $client = new FinnhubClient(sleeper: function (int $microseconds) {});

    $result = $client->fetchMetrics('AAPL');

    expect($result)->toBe(['peTTM' => 37.3169]);
});

test('fetchMetricsは429が続きリトライ上限に達しても例外を投げずnullを返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::response(['error' => 'API limit reached'], 429),
    ]);

    $client = new FinnhubClient(sleeper: function (int $microseconds) {});

    $result = $client->fetchMetrics('AAPL');

    expect($result)->toBeNull();
});

test('X-Ratelimit-Remainingが低い値で返った直後の次回呼び出しでは、実際のHTTPリクエストの前にsleeperが呼ばれる', function () {
    $sleptDurations = [];
    $sleeper = function (int $microseconds) use (&$sleptDurations) {
        $sleptDurations[] = $microseconds;
    };

    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::sequence()
            ->push(['metric' => ['peTTM' => 1.0]], 200, ['X-Ratelimit-Remaining' => '1'])
            ->push(['metric' => ['peTTM' => 2.0]], 200, ['X-Ratelimit-Remaining' => '1']),
    ]);

    $client = new FinnhubClient($sleeper);

    $client->fetchMetrics('AAPL');
    // No prior response yet, so nothing should have triggered a wait before
    // this very first outbound call.
    expect($sleptDurations)->toBeEmpty();

    $client->fetchMetrics('MSFT');
    // The previous response reported a remaining-quota count low enough
    // that the client must throttle itself before issuing this call.
    expect($sleptDurations)->not->toBeEmpty();
});

test('X-Ratelimit-Remainingが十分に高い値で返った場合、次回呼び出し前にsleeperは呼ばれない', function () {
    $sleptDurations = [];
    $sleeper = function (int $microseconds) use (&$sleptDurations) {
        $sleptDurations[] = $microseconds;
    };

    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => Http::sequence()
            ->push(['metric' => ['peTTM' => 1.0]], 200, ['X-Ratelimit-Remaining' => '1000'])
            ->push(['metric' => ['peTTM' => 2.0]], 200, ['X-Ratelimit-Remaining' => '1000']),
    ]);

    $client = new FinnhubClient($sleeper);

    $client->fetchMetrics('AAPL');
    $client->fetchMetrics('MSFT');

    expect($sleptDurations)->toBeEmpty();
});

// -----------------------------------------------------------------------
// fetchReportedFinancials
// -----------------------------------------------------------------------

test('fetchReportedFinancials呼び出し時にsymbol・freq=annual・tokenクエリパラメータが設定値で付与される', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::response(finnhubReportedFinancialsFixture(), 200),
    ]);

    $client = new FinnhubClient;

    $client->fetchReportedFinancials('AAPL');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'finnhub.io/api/v1/stock/financials-reported')
            && str_contains($request->url(), 'symbol=AAPL')
            && str_contains($request->url(), 'freq=annual')
            && str_contains($request->url(), 'token=dummy-finnhub-key');
    });
});

test('fetchReportedFinancialsはdefaultのperiods=2で直近2期分をtotal_assets/total_equity/operating_incomeとして抽出する', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::response(finnhubReportedFinancialsFixture(), 200),
    ]);

    $client = new FinnhubClient;

    $result = $client->fetchReportedFinancials('AAPL');

    expect($result)->toBe([
        ['operating_income' => 123216000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
        ['operating_income' => 114301000000.0, 'total_assets' => 352755000000.0, 'total_equity' => 56950000000.0],
    ]);
});

test('fetchReportedFinancialsはperiodsを指定するとその件数分に絞り込まれ、該当conceptが見つからない期はnullで欠損を許容する', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::response(finnhubReportedFinancialsFixture(), 200),
    ]);

    $client = new FinnhubClient;

    $result = $client->fetchReportedFinancials('AAPL', 3);

    expect($result)->toHaveCount(3);
    expect($result[2])->toBe([
        'operating_income' => null, // ic配列が空 -> us-gaap_OperatingIncomeLossが見つからない
        'total_assets' => 352583000000.0,
        'total_equity' => null, // bs配列にus-gaap_StockholdersEquityが存在しない
    ]);
});

test('fetchReportedFinancialsはdataが空配列の場合例外を投げず空配列を返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::response(['data' => []], 200),
    ]);

    $client = new FinnhubClient;

    $result = $client->fetchReportedFinancials('UNKNOWN');

    expect($result)->toBe([]);
});

test('fetchReportedFinancialsは429が返っても例外を投げずリトライし、最終的に200が返れば抽出結果を返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::sequence()
            ->push(['error' => 'API limit reached'], 429)
            ->push(finnhubReportedFinancialsFixture(), 200),
    ]);

    $client = new FinnhubClient(sleeper: function (int $microseconds) {});

    $result = $client->fetchReportedFinancials('AAPL', 1);

    expect($result)->toBe([
        ['operating_income' => 123216000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
    ]);
});

test('fetchReportedFinancialsは429が続きリトライ上限に達しても例外を投げず空配列を返す', function () {
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::response(['error' => 'API limit reached'], 429),
    ]);

    $client = new FinnhubClient(sleeper: function (int $microseconds) {});

    $result = $client->fetchReportedFinancials('AAPL');

    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------
// 回帰テスト（`/review`で判明した確定バグの修正、2026-09-05）
// -----------------------------------------------------------------------

test('fetchReportedFinancialsはconceptが存在してもvalueがnullの場合、0.0ではなくnullを返す（ゼロ除算・誤判定防止）', function () {
    // 一部のXBRL開示では、concept自体は存在するがvalueがnull（未算定・再表示中等）
    // になることがある。(float) null が黙って0.0になると、equity_ratio計算で
    // 「本来unavailableな銘柄」が「自己資本比率0%」の健全性failedに誤判定される。
    Http::fake([
        'finnhub.io/api/v1/stock/financials-reported*' => Http::response([
            'data' => [
                [
                    'report' => [
                        'bs' => [
                            ['concept' => 'us-gaap_Assets', 'value' => 359241000000, 'unit' => 'USD'],
                            ['concept' => 'us-gaap_StockholdersEquity', 'value' => null, 'unit' => 'USD'],
                        ],
                        'ic' => [
                            ['concept' => 'us-gaap_OperatingIncomeLoss', 'value' => null, 'unit' => 'USD'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $client = new FinnhubClient;

    $result = $client->fetchReportedFinancials('AAPL', 1);

    expect($result[0]['total_equity'])->toBeNull();
    expect($result[0]['operating_income'])->toBeNull();
    expect($result[0]['total_assets'])->toEqualWithDelta(359241000000.0, 0.01);
});

test('接続エラー等の例外発生時、送信済みAPIキー（tokenクエリパラメータ）を含まない例外メッセージで再送出する', function () {
    // JQuantsClientはx-api-keyヘッダーでキーを送るため例外メッセージに
    // キーが含まれないが、FinnhubClientはtokenをクエリパラメータで送るため、
    // 接続エラー時にHTTPクライアントの例外メッセージをそのまま伝播させると
    // リクエストURL（token値を含む）がログに残ってしまう
    // （.claude/rules/40-security.md「ログに出してはいけないもの」違反）。
    Http::fake([
        'finnhub.io/api/v1/stock/metric*' => function () {
            throw new ConnectionException(
                'cURL error 28: Operation timed out for https://finnhub.io/api/v1/stock/metric?symbol=AAPL&metric=all&token=super-secret-finnhub-key'
            );
        },
    ]);

    config()->set(['services.finnhub.key' => 'super-secret-finnhub-key']);

    $client = new FinnhubClient;

    try {
        $client->fetchMetrics('AAPL');
        $this->fail('Expected an exception to be thrown.');
    } catch (\Throwable $e) {
        expect($e->getMessage())->not->toContain('super-secret-finnhub-key');
    }
});

// -----------------------------------------------------------------------
// Interface
// -----------------------------------------------------------------------

test('FinnhubClientはFinnhubClientInterfaceを実装している', function () {
    Http::fake();

    $client = new FinnhubClient;

    expect($client)->toBeInstanceOf(FinnhubClientInterface::class);
});
