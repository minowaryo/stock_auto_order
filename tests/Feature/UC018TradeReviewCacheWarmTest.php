<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\TradeReview\ShowTradeReviewAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Jobs\WarmTradeReviewCache;
use App\Models\Holding;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\PriceHistory;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-018 売買の振り返りの計算を先に作り直しておく — Red phase (CHG-0033 Cycle 7e)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - 2026-10-11 本人承認: 「UXが下がらないことの担保」。データが変わった直後に
|     初めて画面を開くと計算の作り直し（実データで約2.5秒）を待つため、データが
|     変わる処理の最後に、キューで作り直しておく。どの操作も待たせない。
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Jobs\WarmTradeReviewCache (ShouldQueue, unique while queued):
|     handle(ShowTradeReviewAction) runs execute(), which rebuilds the one
|     cache entry when the data changed.
|   - It is pushed at the end of: price:track (not --dry-run, not when the
|     lock is held), FetchExternalMarketDataAction (holdings CSV import),
|     RefreshWatchlistMarketDataAction (watchlist refresh), and a confirmed
|     trade history import (ImportTradeHistoryAction::execute; tested in
|     UC017TradeHistoryImportTest). preview() never pushes it.
|
| Expected Red: the job and the dispatches do not exist yet.
|
*/

function cwTrade(string $code, string $date): TradeExecution
{
    $holding = Holding::create([
        'symbol_code' => $code, 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => 'jp', 'trade_date' => $date, 'account_type' => 'specific', 'kind' => 'buy',
        'quantity' => 1, 'unit_price' => 100, 'price_currency' => 'jpy', 'settlement_currency' => 'jpy', 'settlement_amount_jpy' => 100,
        'content_hash' => str_pad($code, 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0, 'source_row' => [],
        'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

function cwBindRefreshFakes(): void
{
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient);
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient(['nikkei225' => [], 'sp500' => []]));
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    Sleep::fake();
});

describe('UC-018 売買の振り返りの計算の作り直しジョブ', function () {
    test('ジョブを実行すると、最新のデータで売買の振り返りの計算が作られる', function () {
        // Arrange
        Cache::forget('trade-review');
        $trade = cwTrade('1111', '2026-09-08');

        // Act
        (new WarmTradeReviewCache)->handle(app(ShowTradeReviewAction::class));

        // Assert
        $cached = Cache::get('trade-review');
        expect($cached)->toBeArray();
        expect(array_column($cached['report']['trades'], 'id'))->toBe([$trade->id]);
    });

    test('price:track が終わったら作り直しジョブを積み、確認だけの実行（dry run）では積まない', function () {
        // Arrange
        Queue::fake();
        app()->instance(PriceBackfillClientInterface::class, new class implements PriceBackfillClientInterface
        {
            public function fetchStock(string $market, string $symbolCode): PriceHistory
            {
                return new PriceHistory(PriceHistory::EMPTY);
            }

            public function fetchIndex(string $indexName): PriceHistory
            {
                return new PriceHistory(PriceHistory::EMPTY);
            }
        });

        // Act / Assert
        $this->artisan('price:track', ['--dry-run' => true])->assertExitCode(0);
        Queue::assertNotPushed(WarmTradeReviewCache::class);
        $this->artisan('price:track')->assertExitCode(0);
        Queue::assertPushed(WarmTradeReviewCache::class, 1);
    });

    test('保有CSVの取込の株価取得（FetchExternalMarketDataAction）が終わったら作り直しジョブを積む', function () {
        // Arrange
        Queue::fake();
        cwBindRefreshFakes();
        $batch = ImportBatch::create([
            'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
            'mutual_fund_filename' => null, 'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
        ]);
        Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert
        Queue::assertPushed(WarmTradeReviewCache::class, 1);
    });

    test('ウォッチリストの一括更新が終わったら作り直しジョブを積む', function () {
        // Arrange
        Queue::fake();
        cwBindRefreshFakes();

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert
        Queue::assertPushed(WarmTradeReviewCache::class, 1);
    });
});
