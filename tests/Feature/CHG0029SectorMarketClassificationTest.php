<?php

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Livewire\Sector\SectorDashboard;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| CHG-0029 / ADR-0020: 米国株・投資信託のセクター分類と市場別・金額付き表示 — Red phase
|--------------------------------------------------------------------------
| UC-005（use-cases.md 業務ルール「米国株・投資信託の分類（ADR-0020）」）。
|
| Red の理由（実装前に失敗する想定）:
|   - sector_classifications.market 列・(market,name) 一意制約が未作成
|   - SectorAllocationCalculator が市場別にグルーピングせず market /
|     allocation_amount を返さない
|   - SectorDashboard ビューに市場見出し・小計・合計額がない
|   - FinnhubClientInterface::fetchIndustry() / FetchExternalMarketDataAction の
|     米国株・投信の分類処理 / sectors:backfill コマンドが存在しない
|
| Gate 4 要確認の前提:
|   - 評価額は既存と同じ quantity × current_price（投信は ÷10,000）
|   - 画面の金額表記は「¥」+ number_format（既存の「売却提案: ¥…」と同じ）
|   - 市場の表示順は 日本株 → 米国株 → 投資信託、見出し文言は
|     「日本株」「米国株」「投資信託」、小計は「小計」、全体は「合計」
|   - Finnhub が業種を返さない（null）／例外の場合は未分類のまま・既存値は保持
|   - sectors:backfill は未分類の保有のみ対象・冪等・既存の分類は上書きしない
*/

function chg29Snapshot(): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);

    return Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
}

/**
 * @param  array<string, mixed>  $holding
 * @param  array<string, mixed>  $snap
 */
function chg29Holding(Snapshot $snapshot, array $holding, array $snap = []): Holding
{
    $model = Holding::create(array_merge([
        'symbol_code' => '7203',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $holding));

    HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $model->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => 1000,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 0.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $snap));

    return $model;
}

function chg29Sector(string $market, string $name): SectorClassification
{
    return SectorClassification::create(['market' => $market, 'name' => $name]);
}

/**
 * @return array<string, mixed>|null
 */
function chg29FindRow(array $rows, string $market, string $name): ?array
{
    foreach ($rows as $row) {
        if (($row['market'] ?? null) === $market && ($row['sector_name'] ?? null) === $name) {
            return $row;
        }
    }

    return null;
}

function chg29Action(?FakeJQuantsClient $jq = null, ?FakeFinnhubClient $finnhub = null, array $usPrices = [], array $jpPrices = []): FetchExternalMarketDataAction
{
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient($jpPrices));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient($usPrices));
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient);
    app()->instance(JQuantsClientInterface::class, $jq ?? new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, $finnhub ?? new FakeFinnhubClient);

    return app(FetchExternalMarketDataAction::class);
}

/**
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function chg29Prices(): array
{
    $history = [];
    $date = new DateTimeImmutable('2024-01-01');

    for ($i = 0; $i < 30; $i++) {
        $history[] = ['date' => $date->modify("+{$i} weeks")->format('Y-m-d'), 'close' => 100.0 + $i, 'volume' => 100000];
    }

    return $history;
}

describe('CHG-0029 スキーマ: sector_classifications.market', function () {
    test('sector_classificationsにmarket列があり、既定値はjpである', function () {
        expect(Schema::hasColumn('sector_classifications', 'market'))->toBeTrue();

        $sector = SectorClassification::create(['name' => '既定値確認']);

        expect($sector->fresh()->market)->toBe('jp');
    });

    test('同じ名前でも市場が違えば別のセクターとして作成できる', function () {
        chg29Sector('jp', 'Technology');
        chg29Sector('us', 'Technology');

        expect(SectorClassification::where('name', 'Technology')->count())->toBe(2);
    });

    test('同じ市場内で同じ名前のセクターは重複作成できない', function () {
        chg29Sector('us', 'Technology');

        expect(fn () => chg29Sector('us', 'Technology'))->toThrow(QueryException::class);
    });
});

describe('CHG-0029 UC-005: 市場別・金額付きのセクター配分（API）', function () {
    test('各セクター行にmarketとallocation_amount（評価額合計・円）が含まれる', function () {
        $snapshot = chg29Snapshot();
        $jpSector = chg29Sector('jp', '電機・精密');
        chg29Holding($snapshot, ['symbol_code' => '6758', 'sector_classification_id' => $jpSector->id], ['quantity' => 100, 'current_price' => 3000]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/sector-dashboard');

        $row = chg29FindRow($response->json('data.sectors'), 'jp', '電機・精密');
        expect($row)->not->toBeNull();
        expect((float) $row['allocation_amount'])->toBe(300000.0);
    });

    test('日本株と米国株は同名のセクターでも統合されず、市場ごとに別行になる', function () {
        $snapshot = chg29Snapshot();
        $jp = chg29Sector('jp', 'Technology');
        $us = chg29Sector('us', 'Technology');
        chg29Holding($snapshot, ['symbol_code' => '1111', 'market' => 'jp', 'sector_classification_id' => $jp->id], ['quantity' => 100, 'current_price' => 1000]);
        chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us', 'sector_classification_id' => $us->id], ['quantity' => 10, 'current_price' => 3000]);

        $rows = $this->actingAs(User::factory()->create())->getJson('/api/sector-dashboard')->json('data.sectors');

        expect((float) chg29FindRow($rows, 'jp', 'Technology')['allocation_amount'])->toBe(100000.0);
        expect((float) chg29FindRow($rows, 'us', 'Technology')['allocation_amount'])->toBe(30000.0);
    });

    test('未分類は市場ごとに別行になり、投資信託の評価額は1万口あたり単価で換算される', function () {
        $snapshot = chg29Snapshot();
        chg29Holding($snapshot, ['symbol_code' => '2222', 'market' => 'jp'], ['quantity' => 100, 'current_price' => 1000]);
        chg29Holding($snapshot, ['symbol_code' => 'MSFT', 'market' => 'us'], ['quantity' => 10, 'current_price' => 5000]);
        chg29Holding($snapshot, ['symbol_code' => 'FUND1', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund'], ['quantity' => 1000000, 'current_price' => 2500]);

        $rows = $this->actingAs(User::factory()->create())->getJson('/api/sector-dashboard')->json('data.sectors');

        expect((float) chg29FindRow($rows, 'jp', '未分類')['allocation_amount'])->toBe(100000.0);
        expect((float) chg29FindRow($rows, 'us', '未分類')['allocation_amount'])->toBe(50000.0);
        expect((float) chg29FindRow($rows, 'mutual_fund', '未分類')['allocation_amount'])->toBe(250000.0);
    });

    test('偏り判定は市場をまたいだ全体に対するセクター比率で行う', function () {
        $snapshot = chg29Snapshot();
        $us = chg29Sector('us', 'Technology');
        $jp = chg29Sector('jp', '銀行');
        // US Technology 800,000 / 全体1,000,000 = 80% -> 偏り警告
        chg29Holding($snapshot, ['symbol_code' => 'NVDA', 'market' => 'us', 'sector_classification_id' => $us->id], ['quantity' => 80, 'current_price' => 10000]);
        chg29Holding($snapshot, ['symbol_code' => '8301', 'market' => 'jp', 'sector_classification_id' => $jp->id], ['quantity' => 200, 'current_price' => 1000]);

        $rows = $this->actingAs(User::factory()->create())->getJson('/api/sector-dashboard')->json('data.sectors');

        expect(chg29FindRow($rows, 'us', 'Technology')['allocation_status'])->toBe('偏り警告');
        expect(chg29FindRow($rows, 'jp', '銀行')['allocation_status'])->toBe('健全');
    });
});

describe('CHG-0029 UC-005: 市場別・小計・合計額の画面表示', function () {
    test('日本株・米国株・投資信託の順に市場見出しが表示される', function () {
        $snapshot = chg29Snapshot();
        chg29Holding($snapshot, ['symbol_code' => 'FUND1', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund'], ['quantity' => 1000000, 'current_price' => 2500]);
        chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us'], ['quantity' => 10, 'current_price' => 1000]);
        chg29Holding($snapshot, ['symbol_code' => '7203', 'market' => 'jp'], ['quantity' => 10, 'current_price' => 1000]);

        Livewire::actingAs(User::factory()->create())
            ->test(SectorDashboard::class)
            ->assertSeeInOrder(['日本株', '米国株', '投資信託']);
    });

    test('各セクターの評価額・市場ごとの小計・全体の合計額が円表記で表示される', function () {
        $snapshot = chg29Snapshot();
        $banks = chg29Sector('jp', '銀行');
        $autos = chg29Sector('jp', '自動車・輸送機');
        $tech = chg29Sector('us', 'Technology');
        // JP: 銀行 300,000 + 自動車 100,000 = 小計 400,000
        chg29Holding($snapshot, ['symbol_code' => '8301', 'market' => 'jp', 'sector_classification_id' => $banks->id], ['quantity' => 100, 'current_price' => 3000]);
        chg29Holding($snapshot, ['symbol_code' => '7203', 'market' => 'jp', 'sector_classification_id' => $autos->id], ['quantity' => 100, 'current_price' => 1000]);
        // US: Technology 200,000 = 小計 200,000
        chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us', 'sector_classification_id' => $tech->id], ['quantity' => 20, 'current_price' => 10000]);
        // 投信: 250,000
        chg29Holding($snapshot, ['symbol_code' => 'FUND1', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund'], ['quantity' => 1000000, 'current_price' => 2500]);

        // 合計 = 400,000 + 200,000 + 250,000 = 850,000
        Livewire::actingAs(User::factory()->create())
            ->test(SectorDashboard::class)
            ->assertSee('¥300,000')
            ->assertSee('¥100,000')
            ->assertSee('¥200,000')
            ->assertSee('小計')
            ->assertSee('¥400,000')
            ->assertSee('合計')
            ->assertSee('¥850,000');
    });

    test('構成比は小数1桁で表示される', function () {
        $snapshot = chg29Snapshot();
        $sector = chg29Sector('jp', '銀行');
        chg29Holding($snapshot, ['symbol_code' => '8301', 'sector_classification_id' => $sector->id], ['quantity' => 1, 'current_price' => 1000]);
        chg29Holding($snapshot, ['symbol_code' => '7203'], ['quantity' => 2, 'current_price' => 1000]);

        // 銀行 1/3 = 33.333...% -> 33.3%
        Livewire::actingAs(User::factory()->create())
            ->test(SectorDashboard::class)
            ->assertSee('33.3%')
            ->assertDontSee('33.33');
    });
});

describe('CHG-0029 FetchExternalMarketDataAction: 米国株・投資信託の分類', function () {
    test('米国株はFinnhubの業種でmarket=usのセクターに分類される', function () {
        $snapshot = chg29Snapshot();
        $holding = chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us']);
        $batch = ImportBatch::first();

        chg29Action(finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology']), usPrices: ['AAPL' => chg29Prices()])
            ->execute($batch);

        $sector = $holding->fresh()->sectorClassification;
        expect($sector)->not->toBeNull();
        expect($sector->market)->toBe('us');
        expect($sector->name)->toBe('Technology');
    });

    test('同じ業種の米国株は同じセクター行を共有する', function () {
        $snapshot = chg29Snapshot();
        $a = chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us']);
        $b = chg29Holding($snapshot, ['symbol_code' => 'MSFT', 'market' => 'us']);

        chg29Action(
            finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology', 'MSFT' => 'Technology']),
            usPrices: ['AAPL' => chg29Prices(), 'MSFT' => chg29Prices()],
        )->execute(ImportBatch::first());

        expect($a->fresh()->sector_classification_id)->toBe($b->fresh()->sector_classification_id);
        expect(SectorClassification::where('market', 'us')->where('name', 'Technology')->count())->toBe(1);
    });

    test('Finnhubが業種を返さない・例外を投げる米国株は未分類のまま、他の銘柄の分類は続行される', function () {
        $snapshot = chg29Snapshot();
        $unknown = chg29Holding($snapshot, ['symbol_code' => 'ZZZZ', 'market' => 'us']);
        $failing = chg29Holding($snapshot, ['symbol_code' => 'FAIL', 'market' => 'us']);
        $ok = chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us']);

        chg29Action(
            finnhub: new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology'], throwsForIndustry: ['FAIL']),
            usPrices: ['ZZZZ' => chg29Prices(), 'FAIL' => chg29Prices(), 'AAPL' => chg29Prices()],
        )->execute(ImportBatch::first());

        expect($unknown->fresh()->sector_classification_id)->toBeNull();
        expect($failing->fresh()->sector_classification_id)->toBeNull();
        expect($ok->fresh()->sectorClassification?->name)->toBe('Technology');
    });

    test('業種取得に失敗した米国株は、既存の分類を保持する', function () {
        $snapshot = chg29Snapshot();
        $existing = chg29Sector('us', 'Healthcare');
        $holding = chg29Holding($snapshot, ['symbol_code' => 'FAIL', 'market' => 'us', 'sector_classification_id' => $existing->id]);

        chg29Action(
            finnhub: new FakeFinnhubClient(throwsForIndustry: ['FAIL']),
            usPrices: ['FAIL' => chg29Prices()],
        )->execute(ImportBatch::first());

        expect($holding->fresh()->sector_classification_id)->toBe($existing->id);
    });

    test('投資信託は固定カテゴリ「投資信託」（market=mutual_fund）に分類される', function () {
        $snapshot = chg29Snapshot();
        $fund = chg29Holding($snapshot, ['symbol_code' => 'FUND1', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund']);

        chg29Action()->execute(ImportBatch::first());

        $sector = $fund->fresh()->sectorClassification;
        expect($sector)->not->toBeNull();
        expect($sector->market)->toBe('mutual_fund');
        expect($sector->name)->toBe('投資信託');
    });

    test('日本株のJ-Quants分類はmarket=jpで作成される', function () {
        $snapshot = chg29Snapshot();
        $holding = chg29Holding($snapshot, ['symbol_code' => '7203', 'market' => 'jp']);

        chg29Action(
            jq: new FakeJQuantsClient(['7203' => ['code' => '6', 'name' => '自動車・輸送機']]),
            jpPrices: ['7203' => chg29Prices()],
        )->execute(ImportBatch::first());

        expect($holding->fresh()->sectorClassification->market)->toBe('jp');
    });
});

describe('CHG-0029 sectors:backfill コマンド', function () {
    test('未分類の日本株・米国株・投資信託の保有に分類を一括反映する', function () {
        $snapshot = chg29Snapshot();
        $jp = chg29Holding($snapshot, ['symbol_code' => '7203', 'market' => 'jp']);
        $us = chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us']);
        $fund = chg29Holding($snapshot, ['symbol_code' => 'FUND1', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund']);

        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(['7203' => ['code' => '6', 'name' => '自動車・輸送機']]));
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology']));

        $this->artisan('sectors:backfill')->assertExitCode(0);

        expect($jp->fresh()->sectorClassification?->name)->toBe('自動車・輸送機');
        expect($us->fresh()->sectorClassification?->name)->toBe('Technology');
        expect($fund->fresh()->sectorClassification?->name)->toBe('投資信託');
    });

    test('既存の分類は上書きせず、2回実行しても結果とセクター行数が変わらない', function () {
        $snapshot = chg29Snapshot();
        $existing = chg29Sector('us', 'Healthcare');
        $kept = chg29Holding($snapshot, ['symbol_code' => 'JNJ', 'market' => 'us', 'sector_classification_id' => $existing->id]);
        $us = chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us']);

        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology', 'JNJ' => 'Technology']));

        $this->artisan('sectors:backfill')->assertExitCode(0);
        $countAfterFirst = SectorClassification::count();
        $this->artisan('sectors:backfill')->assertExitCode(0);

        expect($kept->fresh()->sector_classification_id)->toBe($existing->id);
        expect($us->fresh()->sectorClassification?->name)->toBe('Technology');
        expect(SectorClassification::count())->toBe($countAfterFirst);
    });

    test('一部の銘柄の取得に失敗しても残りの分類を続行し、終了コードは0である', function () {
        $snapshot = chg29Snapshot();
        $failing = chg29Holding($snapshot, ['symbol_code' => 'FAIL', 'market' => 'us']);
        $ok = chg29Holding($snapshot, ['symbol_code' => 'AAPL', 'market' => 'us']);

        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(industryResponses: ['AAPL' => 'Technology'], throwsForIndustry: ['FAIL']));

        $this->artisan('sectors:backfill')->assertExitCode(0);

        expect($failing->fresh()->sector_classification_id)->toBeNull();
        expect($ok->fresh()->sectorClassification?->name)->toBe('Technology');
    });
});
