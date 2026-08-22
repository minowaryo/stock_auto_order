<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\JQuantsClient;
use App\Services\MarketData\JQuantsClientInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| JQuantsClient — Red phase Unit Test (V2 / APIキー方式)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0005-jquants-api-v2-migration.md (Accepted: V1トークン
|     方式は403 Forbiddenで機能せず廃止、V2のAPIキー方式（x-api-keyヘッダー）
|     に全面移行。エンドポイント・レスポンス形式もV2準拠に書き換え)
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1: J-Quants
|     クライアントをapp/Services/MarketData/にInterface化して配置し、Feature
|     Testでは実APIを叩かずFakeに差し替える方針。ただしこのUnit Testでは
|     JQuantsClient自体を対象とするため実クラスをHttp::fake()で検証する)
|   - docs/architecture/data-model.md (`fundamental_indicators.eps_growth`/
|     `peg_ratio`、`financial_statements.eps`の算出元がJ-Quants財務諸表)
|   - config/services.php (`services.jquants.api_key` ← .envの
|     JQUANTS_API_KEY。ADR-0005に合わせて既に更新済み)
|   - Task description（V2エンドポイント・レスポンスのカラム名短縮・
|     JQuantsClientのメソッドシグネチャ・変換仕様を直接指定）
|
| App\Services\MarketData\JQuantsClient and
| App\Services\MarketData\JQuantsClientInterface do not exist yet (no file
| under app/Services/MarketData/ named JQuantsClient.php /
| JQuantsClientInterface.php). Every test below is expected to fail with a
| fatal "Class/Interface not found" error — either at the
| `JQuantsClient::class` reference used for the `implements` assertion, or
| at the `new JQuantsClient()` line inside the Act step — this is the
| intentional, expected Red state (same convention as the other MarketData
| Red-phase tests in this directory, e.g.
| tests/Unit/Services/MarketData/YahooFinanceChartClientTest.php).
|
| No token cache is involved anymore (V2 uses a static API key, not a
| refreshable token pair) — no RefreshDatabase trait is used here either,
| only `uses(TestCase::class)`, since this client makes outbound HTTP calls
| via Illuminate\Support\Facades\Http (Http::fake() requires the Laravel
| container to be bootstrapped) but does not touch the DB.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Constructor signature: `new JQuantsClient()` (no constructor args;
|     the API key is read internally from config('services.jquants.api_key')
|     per the task description).
|   - GET /v2/equities/master?code={symbolCode} and
|     GET /v2/fins/summary?code={symbolCode} both carry the API key via an
|     `x-api-key` request header (not Authorization/Bearer, per ADR-0005).
|   - Interface method signatures on JQuantsClientInterface mirror the
|     class exactly: fetchSectorInfo(string $symbolCode): ?array and
|     fetchStatements(string $symbolCode, int $periods = 5): array.
|
*/

uses(TestCase::class);

beforeEach(function () {
    config()->set([
        'services.jquants.api_key' => 'dummy-api-key',
    ]);
});

test('fetchSectorInfo呼び出し時にx-api-keyヘッダーが設定値で付与される', function () {
    // Arrange
    Http::fake([
        'api.jquants.com/v2/equities/master*' => Http::response([
            'data' => [
                ['Date' => '2022-11-11', 'Code' => '86970', 'CoName' => '日本取引所グループ', 'CoNameEn' => 'Japan Exchange Group,Inc.', 'S17' => '16', 'S17Nm' => '金融（除く銀行）', 'S33' => '7200', 'S33Nm' => 'その他金融業', 'ScaleCat' => 'TOPIX Large70', 'Mkt' => '0111', 'MktNm' => 'プライム'],
            ],
        ], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $client->fetchSectorInfo('86970');

    // Assert
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.jquants.com/v2/equities/master')
            && str_contains($request->url(), 'code=86970')
            && $request->hasHeader('x-api-key', 'dummy-api-key');
    });
});

test('fetchStatements呼び出し時にx-api-keyヘッダーが設定値で付与される', function () {
    // Arrange
    Http::fake([
        'api.jquants.com/v2/fins/summary*' => Http::response([
            'data' => [
                ['DiscDate' => '2025-05-09', 'DiscTime' => '12:00', 'Code' => '72030', 'Sales' => '45095325000000', 'OP' => '4795586000000', 'OdP' => '5000000000000', 'NP' => '4765461000000', 'EPS' => '347.49', 'BPS' => '3210.5', 'EqAR' => '38.7', 'ShEq' => '20000000000000', 'ROE' => '15.2', 'DivAnn' => '90', 'PayoutRatioAnn' => '25.9'],
            ],
        ], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $client->fetchStatements('72030');

    // Assert
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.jquants.com/v2/fins/summary')
            && str_contains($request->url(), 'code=72030')
            && $request->hasHeader('x-api-key', 'dummy-api-key');
    });
});

test('fetchSectorInfoはdata[0]からcode/nameをS17/S17Nmから正しくマッピングして返す', function () {
    // Arrange
    Http::fake([
        'api.jquants.com/v2/equities/master*' => Http::response([
            'data' => [
                ['Date' => '2022-11-11', 'Code' => '86970', 'CoName' => '日本取引所グループ', 'CoNameEn' => 'Japan Exchange Group,Inc.', 'S17' => '16', 'S17Nm' => '金融（除く銀行）', 'S33' => '7200', 'S33Nm' => 'その他金融業', 'ScaleCat' => 'TOPIX Large70', 'Mkt' => '0111', 'MktNm' => 'プライム'],
            ],
        ], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $result = $client->fetchSectorInfo('86970');

    // Assert
    expect($result)->toBe([
        'code' => '16',
        'name' => '金融（除く銀行）',
    ]);
});

test('fetchSectorInfoはdataが空配列の場合nullを返す', function () {
    // Arrange: symbol not found on the equities/master endpoint
    Http::fake([
        'api.jquants.com/v2/equities/master*' => Http::response(['data' => []], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $result = $client->fetchSectorInfo('00000');

    // Assert
    expect($result)->toBeNull();
});

test('fetchStatementsはDiscDate降順に並べ替え、指定件数に絞り込み、roeを含む数値項目を文字列からfloatへ変換する', function () {
    // Arrange: three fiscal periods returned out of order; only the two most
    // recent should be kept (periods=2). The most recent period (2025-05-09)
    // has an empty-string dividend, which must be coerced to null.
    Http::fake([
        'api.jquants.com/v2/fins/summary*' => Http::response([
            'data' => [
                ['DiscDate' => '2023-05-10', 'DiscTime' => '12:00', 'Code' => '72030', 'Sales' => '40000000000000', 'OP' => '4000000000000', 'OdP' => '4200000000000', 'NP' => '3800000000000', 'EPS' => '300.00', 'BPS' => '2500.0', 'EqAR' => '35.0', 'ShEq' => '18000000000000', 'ROE' => '12.0', 'DivAnn' => '65', 'PayoutRatioAnn' => '21.0'],
                ['DiscDate' => '2024-05-08', 'DiscTime' => '12:00', 'Code' => '72030', 'Sales' => '45095325000000', 'OP' => '5352934000000', 'OdP' => '5500000000000', 'NP' => '4944933000000', 'EPS' => '333.62', 'BPS' => '2870.0', 'EqAR' => '36.8', 'ShEq' => '19000000000000', 'ROE' => '13.5', 'DivAnn' => '75', 'PayoutRatioAnn' => '22.5'],
                ['DiscDate' => '2025-05-09', 'DiscTime' => '12:00', 'Code' => '72030', 'Sales' => '45095325000000', 'OP' => '4795586000000', 'OdP' => '5000000000000', 'NP' => '4765461000000', 'EPS' => '347.49', 'BPS' => '3210.5', 'EqAR' => '38.7', 'ShEq' => '20000000000000', 'ROE' => '15.2', 'DivAnn' => '', 'PayoutRatioAnn' => '25.9'],
            ],
        ], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $result = $client->fetchStatements('72030', 2);

    // Assert
    expect($result)->toBe([
        [
            'disclosed_date' => '2025-05-09',
            'net_sales' => 45095325000000.0,
            'operating_profit' => 4795586000000.0,
            'profit' => 4765461000000.0,
            'eps' => 347.49,
            'book_value_per_share' => 3210.5,
            'equity_to_asset_ratio' => 38.7,
            'roe' => 15.2,
            'dividend_per_share_annual' => null,
            'payout_ratio_annual' => 25.9,
        ],
        [
            'disclosed_date' => '2024-05-08',
            'net_sales' => 45095325000000.0,
            'operating_profit' => 5352934000000.0,
            'profit' => 4944933000000.0,
            'eps' => 333.62,
            'book_value_per_share' => 2870.0,
            'equity_to_asset_ratio' => 36.8,
            'roe' => 13.5,
            'dividend_per_share_annual' => 75.0,
            'payout_ratio_annual' => 22.5,
        ],
    ]);
});

test('fetchStatementsは取得件数が指定期数より少ない場合は取得できた件数分のみ返す', function () {
    // Arrange: only 2 statements exist, but periods=5 is requested
    Http::fake([
        'api.jquants.com/v2/fins/summary*' => Http::response([
            'data' => [
                ['DiscDate' => '2024-05-08', 'DiscTime' => '12:00', 'Code' => '72030', 'Sales' => '45095325000000', 'OP' => '5352934000000', 'OdP' => '5500000000000', 'NP' => '4944933000000', 'EPS' => '333.62', 'BPS' => '2870.0', 'EqAR' => '36.8', 'ShEq' => '19000000000000', 'ROE' => '13.5', 'DivAnn' => '75', 'PayoutRatioAnn' => '22.5'],
                ['DiscDate' => '2025-05-09', 'DiscTime' => '12:00', 'Code' => '72030', 'Sales' => '45095325000000', 'OP' => '4795586000000', 'OdP' => '5000000000000', 'NP' => '4765461000000', 'EPS' => '347.49', 'BPS' => '3210.5', 'EqAR' => '38.7', 'ShEq' => '20000000000000', 'ROE' => '15.2', 'DivAnn' => '90', 'PayoutRatioAnn' => '25.9'],
            ],
        ], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $result = $client->fetchStatements('72030', 5);

    // Assert: both periods returned, still sorted descending, none padded/fabricated
    expect($result)->toHaveCount(2);
    expect(array_column($result, 'disclosed_date'))->toBe(['2025-05-09', '2024-05-08']);
});

test('fetchStatementsはdataが空配列の場合例外を投げず空配列を返す', function () {
    // Arrange: symbol not found on the fins/summary endpoint
    Http::fake([
        'api.jquants.com/v2/fins/summary*' => Http::response(['data' => []], 200),
    ]);

    $client = new JQuantsClient;

    // Act
    $result = $client->fetchStatements('00000', 5);

    // Assert
    expect($result)->toBe([]);
});

test('JQuantsClientはJQuantsClientInterfaceを実装している', function () {
    // Arrange
    Http::fake();

    // Act
    $client = new JQuantsClient;

    // Assert
    expect($client)->toBeInstanceOf(JQuantsClientInterface::class);
});
