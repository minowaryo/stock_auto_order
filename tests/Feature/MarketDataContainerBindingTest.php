<?php

namespace Tests\Feature;

use App\Services\MarketData\JpStockPriceClient;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClient;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClient;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClient;
use App\Services\MarketData\UsStockPriceClientInterface;

/*
|--------------------------------------------------------------------------
| MarketData Interface Container Binding — regression test
|--------------------------------------------------------------------------
|
| Regression test for a /review finding: the 4 MarketData interfaces
| (JpStockPriceClientInterface, UsStockPriceClientInterface,
| MarketIndexClientInterface, JQuantsClientInterface) must resolve to
| their real implementation classes out of the container without any
| app()->instance() Fake override, per the bindings already registered in
| App\Providers\AppServiceProvider::register(). No app()->instance()
| override is performed here (unlike
| tests/Feature/FetchExternalMarketDataActionTest.php's femdAction()
| helper, which intentionally swaps in Fakes) so this test exercises the
| provider's real bind() registrations directly. RefreshDatabase is not
| used since container resolution does not touch the database.
|
*/

describe('MarketDataインターフェースのコンテナ束縛（AppServiceProvider）', function () {
    test('JpStockPriceClientInterfaceはJpStockPriceClientに解決される', function () {
        expect(app(JpStockPriceClientInterface::class))->toBeInstanceOf(JpStockPriceClient::class);
    });

    test('UsStockPriceClientInterfaceはUsStockPriceClientに解決される', function () {
        expect(app(UsStockPriceClientInterface::class))->toBeInstanceOf(UsStockPriceClient::class);
    });

    test('MarketIndexClientInterfaceはMarketIndexClientに解決される', function () {
        expect(app(MarketIndexClientInterface::class))->toBeInstanceOf(MarketIndexClient::class);
    });

    test('JQuantsClientInterfaceはJQuantsClientに解決される', function () {
        expect(app(JQuantsClientInterface::class))->toBeInstanceOf(JQuantsClient::class);
    });
});
