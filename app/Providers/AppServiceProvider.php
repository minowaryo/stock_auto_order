<?php

namespace App\Providers;

use App\Services\MarketData\FinnhubClient;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClient;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClient;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClient;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClient;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // app/Services/MarketData/ clients (ADR-0004): bind each interface to
        // its real implementation so production code (e.g.
        // FetchExternalMarketDataAction) can be resolved from the container.
        // Feature Tests override these with app()->instance() Fakes.
        $this->app->bind(JpStockPriceClientInterface::class, JpStockPriceClient::class);
        $this->app->bind(UsStockPriceClientInterface::class, UsStockPriceClient::class);
        $this->app->bind(MarketIndexClientInterface::class, MarketIndexClient::class);
        $this->app->bind(JQuantsClientInterface::class, JQuantsClient::class);
        $this->app->bind(FinnhubClientInterface::class, FinnhubClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
