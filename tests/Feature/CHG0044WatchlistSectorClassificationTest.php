<?php

use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\WatchlistItem;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| CHG-0044 / ADR-0020 追補D6: ウォッチリスト銘柄のセクター分類 — Red phase
|--------------------------------------------------------------------------
| UC-012（一括更新フロー3「JP株は業種分類…、US株は業種（Finnhub）…を取得し
| holdings.sector_classification_idに反映する」）。
|
| Red の理由: RefreshWatchlistMarketDataAction は業種を保存しておらず、
| sectors:backfill は最新スナップショットの保有のみを対象にしている。
|
| Gate 4 要確認の前提:
|   - 業種の取得失敗・業種不明は、未分類のまま（既存値は保持）で銘柄の更新を
|     続行し、failed_count には数えない
|   - sectors:backfill の対象は「最新スナップショットの保有」＋「ウォッチリスト
|     の銘柄」。どちらでもない過去の銘柄にはAPIを呼ばない
|   - 米国ETF専用カテゴリは対象外（業種不明のまま）
*/

function chg44Item(string $symbol, string $market, ?int $sectorId = null): WatchlistItem
{
    $holding = Holding::create([
        'symbol_code' => $symbol, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => $symbol.'銘柄', 'sector_classification_id' => $sectorId,
        'first_detected_at' => now(),
    ]);

    return WatchlistItem::create([
        'holding_id' => $holding->id, 'folder_name' => 'テーマ',
        'exchange_label' => $market === 'jp' ? '東Ｐ' : '米国',
        'source' => 'rakuten_favorites_csv', 'last_seen_in_csv_at' => now(), 'registered_at' => now(),
    ]);
}

/**
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function chg44Prices(): array
{
    $history = [];
    $date = new DateTimeImmutable('2024-01-01');

    for ($i = 0; $i < 30; $i++) {
        $history[] = ['date' => $date->modify("+{$i} weeks")->format('Y-m-d'), 'close' => 100.0 + $i, 'volume' => 100000];
    }

    return $history;
}

function chg44Bind(?FakeJQuantsClient $jq = null, ?FakeFinnhubClient $finnhub = null, array $symbols = []): void
{
    $prices = array_fill_keys($symbols, chg44Prices());

    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient($prices));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient($prices));
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient);
    app()->instance(JQuantsClientInterface::class, $jq ?? new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, $finnhub ?? new FakeFinnhubClient);
}

function chg44Snapshot(): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
        'mutual_fund_filename' => null, 'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
    ]);

    return Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
}

describe('CHG-0044 ウォッチリスト一括更新: セクター分類', function () {
    test('日本株のウォッチリスト銘柄はJ-Quantsの業種でmarket=jpのセクターに分類される', function () {
        $item = chg44Item('7203', 'jp');
        chg44Bind(jq: new FakeJQuantsClient(['7203' => ['code' => '6', 'name' => '自動車・輸送機']]), symbols: ['7203']);

        app(RefreshWatchlistMarketDataAction::class)->execute();

        $sector = $item->holding->fresh()->sectorClassification;
        expect($sector?->market)->toBe('jp');
        expect($sector?->name)->toBe('自動車・輸送機');
    });

    test('米国株のウォッチリスト銘柄はFinnhubの業種でmarket=usのセクターに分類される', function () {
        $item = chg44Item('AAPL', 'us');
        chg44Bind(finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology']), symbols: ['AAPL']);

        app(RefreshWatchlistMarketDataAction::class)->execute();

        $sector = $item->holding->fresh()->sectorClassification;
        expect($sector?->market)->toBe('us');
        expect($sector?->name)->toBe('Technology');
    });

    test('業種の取得に失敗しても銘柄の更新は続行され、failed_countに数えられず、既存の分類は保持される', function () {
        $existing = SectorClassification::create(['market' => 'us', 'name' => 'Healthcare']);
        $failing = chg44Item('FAIL', 'us', $existing->id);
        $failingJp = chg44Item('9999', 'jp');
        $ok = chg44Item('AAPL', 'us');
        chg44Bind(
            jq: new FakeJQuantsClient(throwsForSectorInfo: ['9999']),
            finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology'], throwsForIndustry: ['FAIL']),
            symbols: ['FAIL', '9999', 'AAPL'],
        );

        $run = app(RefreshWatchlistMarketDataAction::class)->execute();

        expect($failing->holding->fresh()->sector_classification_id)->toBe($existing->id);
        expect($failingJp->holding->fresh()->sector_classification_id)->toBeNull();
        expect($ok->holding->fresh()->sectorClassification?->name)->toBe('Technology');
        expect($failing->fresh()->last_refreshed_at)->not->toBeNull();
        expect($run->failed_count)->toBe(0);
        expect($run->processed_count)->toBe(3);
    });

    test('業種が不明（null）の銘柄は未分類のままで、セクター行は作られない', function () {
        $item = chg44Item('HDV', 'us');
        chg44Bind(symbols: ['HDV']);

        app(RefreshWatchlistMarketDataAction::class)->execute();

        expect($item->holding->fresh()->sector_classification_id)->toBeNull();
        expect(SectorClassification::count())->toBe(0);
    });

    test('同じ業種のウォッチリスト銘柄は同じセクター行を共有する', function () {
        $a = chg44Item('AAPL', 'us');
        $b = chg44Item('MSFT', 'us');
        chg44Bind(finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology', 'MSFT' => 'Technology']), symbols: ['AAPL', 'MSFT']);

        app(RefreshWatchlistMarketDataAction::class)->execute();

        expect($a->holding->fresh()->sector_classification_id)->toBe($b->holding->fresh()->sector_classification_id);
        expect(SectorClassification::where('market', 'us')->count())->toBe(1);
    });
});

describe('CHG-0044 sectors:backfill: ウォッチリスト銘柄も対象', function () {
    test('スナップショットに無いウォッチリストの日本株・米国株にも分類を反映する', function () {
        chg44Snapshot();
        $jp = chg44Item('7203', 'jp');
        $us = chg44Item('AAPL', 'us');
        chg44Bind(
            jq: new FakeJQuantsClient(['7203' => ['code' => '6', 'name' => '自動車・輸送機']]),
            finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology']),
        );

        $this->artisan('sectors:backfill')->assertExitCode(0);

        expect($jp->holding->fresh()->sectorClassification?->name)->toBe('自動車・輸送機');
        expect($us->holding->fresh()->sectorClassification?->name)->toBe('Technology');
    });

    test('保有とウォッチリストのどちらでもない過去の銘柄にはAPIを呼ばず、未分類のままにする', function () {
        chg44Snapshot();
        $old = Holding::create([
            'symbol_code' => 'OLD1', 'market' => 'us', 'instrument_type' => 'stock',
            'symbol_name' => '過去銘柄', 'sector_classification_id' => null, 'first_detected_at' => now(),
        ]);
        chg44Bind(finnhub: new FakeFinnhubClient(industryResponses: ['OLD1' => 'Technology']));

        $this->artisan('sectors:backfill')->assertExitCode(0);

        expect($old->fresh()->sector_classification_id)->toBeNull();
    });

    test('保有とウォッチリストの両方にある銘柄を二重に処理せず、既存の分類を上書きしない', function () {
        $snapshot = chg44Snapshot();
        $existing = SectorClassification::create(['market' => 'us', 'name' => 'Healthcare']);
        $item = chg44Item('JNJ', 'us', $existing->id);
        HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id, 'holding_id' => $item->holding_id, 'quantity' => 1,
            'average_cost' => 1, 'current_price' => 1, 'fx_rate_used' => null,
            'unrealized_gain_amount' => 0, 'unrealized_gain_rate' => 0.0, 'ma20' => null, 'ma75' => null,
            'is_newly_detected' => false,
        ]);
        chg44Bind(finnhub: new FakeFinnhubClient(industryResponses: ['JNJ' => 'Technology']));

        $this->artisan('sectors:backfill')->assertExitCode(0);

        expect($item->holding->fresh()->sector_classification_id)->toBe($existing->id);
    });
});
