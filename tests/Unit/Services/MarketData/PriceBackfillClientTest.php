<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\PriceBackfillClient;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\YahooFinanceChartClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| 価格の初回補完クライアント — Red phase (CHG-0033 Cycle 6a)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0024-trade-and-signal-price-tracking.md D5（初回の一括補完）
|   - docs/adr/ADR-0027-trade-history-storage.md D5・D6（usdjpy・stock_splits）
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D5（終値は分割遡及調整済み・
|     配当は未調整）
|
| Facts checked against the real Yahoo chart API on 2026-10-05 (public market
| data, a handful of requests):
|   - range=10y&interval=1wk returns ~524 weekly bars (back to 2016-10);
|     range=5y starts at 2021-10, which is too short for the price-based
|     features (MA75 / 52-week high) of 2022 trades; range=max degrades old
|     data to monthly bars, so it is not used.
|   - events=splits adds chart.result[0].events.splits keyed by timestamp:
|     {date, numerator, denominator, splitRatio}; JP and US stocks both have it.
|   - quote.close is split-adjusted (continuous across a split).
|   - USD/JPY is the symbol JPY=X (volume is always 0).
|   - Unknown symbols answer HTTP 404; rate limiting answers 429.
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - YahooFinanceChartClient::fetchHistory(string $symbol, string $range,
|     bool $withSplits = false): PriceHistory. The existing
|     fetchWeeklyHistory($symbol, $weeks) keeps its behaviour (range=2y, no
|     events); both apply the same bar rules (placeholder drop, week folding).
|   - PriceHistory (readonly, App\Services\MarketData\PriceHistory):
|       status  'ok' | 'empty' | 'not_found' | 'failed'
|       rows    list<array{date: Y-m-d, close: float, volume: int}>
|       splits  list<array{date: Y-m-d, numerator: int, denominator: int}>
|               ascending by date; only whole-number ratios are kept
|       splitsIncomplete bool  true when a split was dropped (not whole-number
|               or unreadable) so the caller can mark 分割情報欠損
|       message ?string  reason for 'failed' (never contains a URL with
|               secrets; Yahoo needs none)
|     Mapping: 200 with usable bars → ok; 200 with no result / no usable bars
|     → empty; HTTP 404 → not_found; any other non-2xx or a connection error
|     → failed. Nothing is thrown.
|   - PriceBackfillClientInterface / PriceBackfillClient:
|       fetchStock(string $market, string $symbolCode): PriceHistory
|         jp → "{code}.T", us → ticker as-is; range=10y; splits requested
|       fetchIndex(string $indexName): PriceHistory
|         nikkei225 ^N225, sp500 ^GSPC, sox ^SOX, usdjpy JPY=X; range=10y;
|         no splits; unknown name → InvalidArgumentException
|     Bound to the real class in AppServiceProvider.
|
| Pure Unit tests: Http::fake() intercepts every call; no DB.
|
| Expected Red: PriceHistory, PriceBackfillClient(+Interface) and
| YahooFinanceChartClient::fetchHistory() do not exist yet.
|
*/

uses(TestCase::class);

/**
 * @param  array<int, int>  $timestamps
 * @param  array<int, float|null>  $closes
 * @param  array<int, int|null>  $volumes
 * @param  array<string, mixed>|null  $events
 * @return array<string, mixed>
 */
function pbcChart(array $timestamps, array $closes, array $volumes, ?array $events = null): array
{
    $result = [
        'timestamp' => $timestamps,
        'indicators' => ['quote' => [['close' => $closes, 'volume' => $volumes]]],
    ];

    if ($events !== null) {
        $result['events'] = $events;
    }

    return ['chart' => ['result' => [$result], 'error' => null]];
}

describe('YahooFinanceChartClient::fetchHistory（取得期間・分割・状態）', function () {
    test('指定した期間（range）と週足（interval=1wk）と分割イベントを要求し、既定の取得は従来どおり2年・イベントなしのまま', function () {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart([1704067200], [100.0], [10]))]);
        $client = new YahooFinanceChartClient;

        // Act
        $client->fetchHistory('7203.T', '10y', true);
        $client->fetchWeeklyHistory('7203.T', 104);

        // Assert
        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->url())->values();
        expect($sent[0])->toContain('/chart/7203.T')->toContain('range=10y')->toContain('interval=1wk')->toContain('events=splits');
        expect($sent[1])->toContain('range=2y')->not->toContain('events');
    });

    test('週足（日付・終値・出来高）を日付昇順で返し、終値か出来高が欠けた週は除く', function () {
        // Arrange: 2024-01-01 / 01-08 (close null) / 01-15
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart(
            [1704067200, 1704672000, 1705276800],
            [100.5, null, 102.25],
            [1000, 900, 1200],
        ))]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y');

        // Assert
        expect($history->status)->toBe('ok');
        expect($history->rows)->toBe([
            ['date' => '2024-01-01', 'close' => 100.5, 'volume' => 1000],
            ['date' => '2024-01-15', 'close' => 102.25, 'volume' => 1200],
        ]);
        expect($history->splits)->toBe([]);
        expect($history->splitsIncomplete)->toBeFalse();
    });

    test('末尾の未確定週（出来高0・前週と同じ終値）と、同じ週の重複バーは、既存の取得と同じ規則で除く', function () {
        // Arrange: 2024-01-01 (Mon), then a Friday bar in the same week, then 01-08, then an unconfirmed tail
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart(
            [1704067200, 1704484800, 1704672000, 1705276800],
            [100.0, 101.0, 102.0, 102.0],
            [1000, 800, 1100, 0],
        ))]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y');

        // Assert: the same-week Friday bar and the unconfirmed tail are gone
        expect(array_column($history->rows, 'date'))->toBe(['2024-01-01', '2024-01-08']);
    });

    test('分割イベントを日付昇順の分子・分母として返す（拡大も縮小も）', function () {
        // Arrange: a 10:1 split and a 1:10 reverse split, out of order in the payload
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart(
            [1704067200], [100.0], [10],
            ['splits' => [
                '1740000000' => ['date' => 1740000000, 'numerator' => 1.0, 'denominator' => 10.0, 'splitRatio' => '1:10'],
                '1718026200' => ['date' => 1718026200, 'numerator' => 10.0, 'denominator' => 1.0, 'splitRatio' => '10:1'],
            ]],
        ))]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y', true);

        // Assert
        expect($history->splits)->toBe([
            ['date' => '2024-06-10', 'numerator' => 10, 'denominator' => 1],
            ['date' => '2025-02-19', 'numerator' => 1, 'denominator' => 10],
        ]);
        expect($history->splitsIncomplete)->toBeFalse();
    });

    test('整数でない分割比は捨て、分割情報が不完全だったことを示す', function () {
        // Arrange: 3:2 is whole numbers (kept); 1.5:1 is not
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart(
            [1704067200], [100.0], [10],
            ['splits' => [
                '1718026200' => ['date' => 1718026200, 'numerator' => 3.0, 'denominator' => 2.0, 'splitRatio' => '3:2'],
                '1740000000' => ['date' => 1740000000, 'numerator' => 1.5, 'denominator' => 1.0, 'splitRatio' => '1.5:1'],
            ]],
        ))]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y', true);

        // Assert
        expect($history->splits)->toBe([['date' => '2024-06-10', 'numerator' => 3, 'denominator' => 2]]);
        expect($history->splitsIncomplete)->toBeTrue();
    });

    test('分割イベントを要求しない場合、応答にあっても分割は返さない', function () {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart(
            [1704067200], [100.0], [10],
            ['splits' => ['1718026200' => ['date' => 1718026200, 'numerator' => 10.0, 'denominator' => 1.0, 'splitRatio' => '10:1']]],
        ))]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y');

        // Assert
        expect($history->splits)->toBe([]);
    });
});

describe('YahooFinanceChartClient::fetchHistory（取得失敗の区別・例外を投げない）', function () {
    test('銘柄が存在しない（HTTP 404）場合は「銘柄なし」', function () {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => null, 'error' => ['code' => 'Not Found', 'description' => 'No data found']]], 404)]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('ZZZZ', '10y');

        // Assert
        expect($history->status)->toBe('not_found');
        expect($history->rows)->toBe([]);
    });

    test('レート制限（429）やサーバーエラー（5xx）は「取得失敗」で、理由にステータスが入る', function (int $status) {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response('Too Many Requests', $status)]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y');

        // Assert
        expect($history->status)->toBe('failed');
        expect($history->message)->toContain((string) $status);
        expect($history->rows)->toBe([]);
    })->with([429, 500, 503]);

    test('接続エラーは例外にせず「取得失敗」にする', function () {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => fn () => throw new ConnectionException('timed out')]);

        // Act
        $history = (new YahooFinanceChartClient)->fetchHistory('NVDA', '10y');

        // Assert
        expect($history->status)->toBe('failed');
        expect($history->message)->toContain('timed out');
    });

    test('200でも結果が空・使える週足がない場合は「データなし」で、失敗とは区別する', function () {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()
            ->push(['chart' => ['result' => [], 'error' => null]])
            ->push(pbcChart([1704067200], [null], [null]))]);
        $client = new YahooFinanceChartClient;

        // Act & Assert
        expect($client->fetchHistory('NVDA', '10y')->status)->toBe('empty');
        expect($client->fetchHistory('NVDA', '10y')->status)->toBe('empty');
    });
});

describe('PriceBackfillClient（市場・銘柄・指数からYahooのシンボルへの対応）', function () {
    $sentUrls = fn () => Http::recorded()->map(fn (array $pair) => $pair[0]->url())->values()->all();

    test('国内株は「コード.T」、米国株はティッカーそのままで、10年・分割ありで取得する', function () use ($sentUrls) {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart([1704067200], [100.0], [10]))]);
        $client = new PriceBackfillClient(new YahooFinanceChartClient);

        // Act
        $jp = $client->fetchStock('jp', '7203');
        $client->fetchStock('us', 'NVDA');

        // Assert
        expect($jp->status)->toBe('ok');
        $urls = $sentUrls();
        expect($urls[0])->toContain('/chart/7203.T')->toContain('range=10y')->toContain('events=splits');
        expect($urls[1])->toContain('/chart/NVDA')->toContain('range=10y')->toContain('events=splits');
    });

    test('指数とドル円は対応するシンボルで、10年・分割なしで取得する', function (string $indexName, string $symbol) use ($sentUrls) {
        // Arrange
        Http::fake(['query1.finance.yahoo.com/*' => Http::response(pbcChart([1704067200], [100.0], [0]))]);

        // Act
        (new PriceBackfillClient(new YahooFinanceChartClient))->fetchIndex($indexName);

        // Assert
        $url = $sentUrls()[0];
        expect(urldecode($url))->toContain('/chart/'.$symbol)->toContain('range=10y')->not->toContain('events');
    })->with([
        ['nikkei225', '^N225'],
        ['sp500', '^GSPC'],
        ['sox', '^SOX'],
        ['usdjpy', 'JPY=X'],
    ]);

    test('未対応の指数名・市場名は例外にする', function () {
        // Arrange
        $client = new PriceBackfillClient(new YahooFinanceChartClient);

        // Act & Assert
        expect(fn () => $client->fetchIndex('vix'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $client->fetchStock('mutual_fund', 'ABC'))->toThrow(InvalidArgumentException::class);
    });

    test('インターフェースは、コンテナから実際のクラスとして解決される', function () {
        // Act & Assert
        expect(app(PriceBackfillClientInterface::class))->toBeInstanceOf(PriceBackfillClient::class);
    });
});
