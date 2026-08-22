<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\FundamentalIndicatorMapper;
use App\Services\Analysis\TechnicalIndicatorCalculator;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| FetchExternalMarketDataAction — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
|   - docs/architecture/data-model.md (technical_indicators /
|     fundamental_indicators / signals / market_indicator_snapshots /
|     holdings.sector_classification_id / sector_classifications)
|   - docs/product/use-cases.md (UC-001 フロー7〜8, UC-002 業務ルール
|     "指標計算はJP株・US株の個別株のみ対象", UC-004 "含み益+20%未満は対象外")
|   - Task description given for this Red-phase generation (12-step spec for
|     App\Actions\Analysis\FetchExternalMarketDataAction, this Action is not
|     yet wired into ImportCsvAction — tested standalone here).
|
| Expected Red state (confirmed by exploration before writing these tests):
|   - `app/Actions/Analysis/` does not exist at all (no
|     FetchExternalMarketDataAction file anywhere under app/). Every test
|     below is expected to fail with a fatal container-resolution error
|     ("Target class [App\Actions\Analysis\FetchExternalMarketDataAction]
|     does not exist.") raised from the `app(FetchExternalMarketDataAction::
|     class)` call inside the femdAction() helper (Act step) — this is the
|     intentional Red state, not a typo (same convention as
|     tests/Feature/UC003HoldingDetailTest.php for HoldingMemo before that
|     model existed).
|   - Separately, the DB schema itself is also not yet ready for this
|     Action's target contract: `technical_indicators`/`fundamental_indicators`
|     do not yet have the ADR-0004 columns (volume, volume_ma20, week52_high,
|     week52_low, relative_strength_vs_market, relative_strength_vs_sector /
|     eps_growth, peg_ratio), `signals.signal_type` does not yet have the
|     4 new enum values, and `market_indicator_snapshots` has no migration
|     at all yet (verified via Glob/Grep against database/migrations/ before
|     writing this file). Because every test fails earlier at the Act step
|     (class does not exist), none of the assertions below are ever reached
|     yet, so this schema gap does not currently change *why* these tests
|     are Red. It DOES mean the Green-phase implementer must add a new
|     ALTER TABLE migration for these columns/table (existing migration
|     files must not be edited, `.claude/rules/20-mysql.md`) before this
|     test file's assertions can pass — flagging this explicitly for Gate 4
|     review since it's an additional prerequisite beyond the Action class
|     itself.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - `market_indicator_snapshots.ma_deviation`'s exact moving-average
|     period is not pinned by data-model.md/ADR-0004 (only `value`/
|     `change_rate` are described precisely enough to compute by hand: "直近
|     値・前日比〔前週比でよい〕"). This test only asserts `ma_deviation` is
|     non-null when enough history is available, not its exact value —
|     confirm the intended MA period at Gate 4 if a precise value should be
|     pinned down instead.
|   - Technical/fundamental indicator expected values are computed by
|     calling the real, already-Green TechnicalIndicatorCalculator /
|     FundamentalIndicatorMapper directly inside the test with the same
|     inputs the Action is specified to compute (own price history +
|     market/sector 13-week benchmark returns it is responsible for
|     deriving), rather than hand-deriving the arithmetic — same "trust
|     already-tested dependency, verify the wiring/business-rule layered on
|     top of it" convention as
|     tests/Unit/Services/Analysis/SignalDeterminationServiceTest.php.
|
*/

/**
 * Builds an arithmetic close-price sequence: [$start, $start+$step, ...]
 * ($count elements total).
 *
 * @return array<int, float>
 */
function femdCloses(float $start, float $step, int $count): array
{
    $closes = [];

    for ($i = 0; $i < $count; $i++) {
        $closes[] = $start + $step * $i;
    }

    return $closes;
}

/**
 * @param  array<int, float>  $closes
 * @param  int|array<int, int>  $volumes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function femdPriceHistory(array $closes, int|array $volumes = 100000, string $startDate = '2024-01-01'): array
{
    $history = [];
    $date = new \DateTimeImmutable($startDate);

    foreach ($closes as $i => $close) {
        $volume = is_array($volumes) ? $volumes[$i] : $volumes;

        $history[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => (int) $volume,
        ];
    }

    return $history;
}

/**
 * Stock/index 13-week return (%), mirroring data-model.md's documented
 * formula for `relative_strength_vs_market`/`relative_strength_vs_sector`:
 * (last close − close 13 weeks before) ÷ close 13 weeks before × 100.
 * Returns null when fewer than 14 data points are available (matches
 * TechnicalIndicatorCalculator's own data-sufficiency rule).
 *
 * @param  array<int, array{date: string, close: float, volume: int}>  $priceHistory
 */
function femd13wReturn(array $priceHistory): ?float
{
    $count = count($priceHistory);

    if ($count < 14) {
        return null;
    }

    $current = (float) $priceHistory[$count - 1]['close'];
    $past = (float) $priceHistory[$count - 14]['close'];

    return ($current - $past) / $past * 100;
}

/**
 * 5-period J-Quants statements fixture (descending, latest-first). Index 0..3
 * share the same "latest" figures; index 4 ("4 periods ago") is deliberately
 * lower so FundamentalIndicatorMapper::calculateGrowth() (which only reads
 * index 0 and 4) produces non-null, non-zero growth rates.
 *
 * @return array<int, array{disclosed_date: string, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>
 */
function femdStatements(): array
{
    $latest = [
        'net_sales' => 120000.0,
        'operating_profit' => 15000.0,
        'profit' => 10000.0,
        'eps' => 120.0,
        'book_value_per_share' => 800.0,
        'equity_to_asset_ratio' => 0.55,
        'roe' => 0.125,
        'dividend_per_share_annual' => 30.0,
        'payout_ratio_annual' => 0.30,
    ];

    $statements = [];

    for ($i = 0; $i < 5; $i++) {
        $statements[] = array_merge($latest, ['disclosed_date' => "2026Q{$i}"]);
    }

    $statements[4] = array_merge($latest, [
        'disclosed_date' => '2025Q1',
        'net_sales' => 100000.0,
        'operating_profit' => 12000.0,
        'eps' => 100.0,
    ]);

    return $statements;
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function femdImportBatch(?\DateTimeInterface $snapshottedAt = null): array
{
    $snapshottedAt ??= now();

    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => $snapshottedAt,
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $snapshottedAt,
    ]);

    return [$batch, $snapshot];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function femdHolding(array $attributes = []): Holding
{
    return Holding::create(array_merge([
        'symbol_code' => '7203',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'トヨタ自動車',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function femdHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 2000,
        'current_price' => 2500,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 5000,
        'unrealized_gain_rate' => 10.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * Binds the 4 MarketData Fakes into the container and resolves the Action
 * under test. This call itself is the expected Red-state trigger (see
 * file-level docblock): App\Actions\Analysis\FetchExternalMarketDataAction
 * does not exist yet.
 */
function femdAction(
    FakeJpStockPriceClient $jpStockPriceClient,
    FakeUsStockPriceClient $usStockPriceClient,
    FakeMarketIndexClient $marketIndexClient,
    FakeJQuantsClient $jQuantsClient,
): FetchExternalMarketDataAction {
    app()->instance(JpStockPriceClientInterface::class, $jpStockPriceClient);
    app()->instance(UsStockPriceClientInterface::class, $usStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, $marketIndexClient);
    app()->instance(JQuantsClientInterface::class, $jQuantsClient);

    return app(FetchExternalMarketDataAction::class);
}

/**
 * @param  array<string, float|int|null>  $expected
 */
function femdAssertTechnicalIndicatorMatches(int $holdingId, array $expected): void
{
    $row = DB::table('technical_indicators')->where('holding_id', $holdingId)->first();

    expect($row)->not->toBeNull();

    foreach ($expected as $column => $value) {
        if ($value === null) {
            expect($row->{$column})->toBeNull();

            continue;
        }

        expect((float) $row->{$column})->toEqualWithDelta((float) $value, 0.01);
    }
}

/**
 * @param  array<string, float|int|null>  $expected
 */
function femdAssertFundamentalIndicatorMatches(int $holdingId, array $expected): void
{
    $row = DB::table('fundamental_indicators')->where('holding_id', $holdingId)->first();

    expect($row)->not->toBeNull();

    foreach ($expected as $column => $value) {
        if ($value === null) {
            expect($row->{$column})->toBeNull();

            continue;
        }

        expect((float) $row->{$column})->toEqualWithDelta((float) $value, 0.01);
    }
}

describe('FetchExternalMarketDataAction: 外部データ取得・指標計算・シグナル判定', function () {
    describe('正常系（JP株）', function () {
        test('JP個別株のテクニカル指標・ファンダメンタルズ指標が保存され、セクター分類が作成・紐付けされる', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => 'トヨタ自動車',
            ]);
            femdHoldingSnapshot($snapshot, $holding, [
                'current_price' => 2500.0,
                'unrealized_gain_rate' => 10.0, // <=20% -> シグナル判定は行われない
            ]);

            $closes = femdCloses(2000.0, 5.0, 80);
            $priceHistory = femdPriceHistory($closes, 100000);

            $nikkeiHistory = femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02');
            $sp500History = femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02');

            $statements = femdStatements();

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient(['nikkei225' => $nikkeiHistory, 'sp500' => $sp500History]),
                new FakeJQuantsClient(['7203' => ['code' => '6050', 'name' => '電気機器']], ['7203' => $statements]),
            );

            $action->execute($batch);

            // Technical indicators: marketReturn13w = nikkei225's own 13w return
            // (this holding is JP); sectorReturn13w = this holding's own 13w
            // return, since it is the sole member of its (newly-assigned)
            // sector within this batch.
            $marketReturn13w = femd13wReturn($nikkeiHistory);
            $ownReturn13w = femd13wReturn($priceHistory);
            $expectedTechnical = (new TechnicalIndicatorCalculator)->calculate($priceHistory, $marketReturn13w, $ownReturn13w);
            femdAssertTechnicalIndicatorMatches($holding->id, $expectedTechnical);

            // Fundamental indicators
            $expectedFundamental = (new FundamentalIndicatorMapper)->map($statements, 2500.0);
            femdAssertFundamentalIndicatorMatches($holding->id, $expectedFundamental);

            // Sector classification
            $this->assertDatabaseHas('sector_classifications', ['name' => '電気機器', 'code' => '6050']);
            $sector = SectorClassification::where('name', '電気機器')->first();
            $holding->refresh();
            expect($holding->sector_classification_id)->toBe($sector->id);
        });

        test('J-Quantsのセクター情報がnullの場合、既存のsector_classification_idは変更されない', function () {
            [$batch, $snapshot] = femdImportBatch();
            $existingSector = SectorClassification::create(['code' => '9999', 'name' => '既存セクター']);
            $holding = femdHolding([
                'symbol_code' => '7203',
                'sector_classification_id' => $existingSector->id,
            ]);
            femdHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 5.0]);

            $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient(['7203' => null]), // sector unresolved
            );

            $action->execute($batch);

            $holding->refresh();
            expect($holding->sector_classification_id)->toBe($existingSector->id);
        });
    });

    describe('正常系（US株）', function () {
        test('US個別株はテクニカル指標のみ保存され、ファンダメンタルズ指標・セクター分類は対象外', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding([
                'symbol_code' => 'AAPL',
                'market' => 'us',
                'instrument_type' => 'stock',
                'symbol_name' => 'Apple Inc.',
            ]);
            femdHoldingSnapshot($snapshot, $holding, [
                'current_price' => 25000.0,
                'fx_rate_used' => 150.0,
                'unrealized_gain_rate' => 8.0,
            ]);

            $priceHistory = femdPriceHistory(femdCloses(150.0, 2.0, 60));
            $sp500History = femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02');

            $action = femdAction(
                new FakeJpStockPriceClient(),
                new FakeUsStockPriceClient(['AAPL' => $priceHistory]),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => $sp500History,
                ]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            $marketReturn13w = femd13wReturn($sp500History);
            $expectedTechnical = (new TechnicalIndicatorCalculator)->calculate($priceHistory, $marketReturn13w, null);
            femdAssertTechnicalIndicatorMatches($holding->id, $expectedTechnical);

            $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $holding->id]);

            $holding->refresh();
            expect($holding->sector_classification_id)->toBeNull();
        });
    });

    describe('ETF・投資信託の除外', function () {
        test('instrument_typeがetf/mutual_fundの銘柄は指標計算の対象外になる', function () {
            [$batch, $snapshot] = femdImportBatch();

            $etfHolding = femdHolding([
                'symbol_code' => '1481',
                'market' => 'jp',
                'instrument_type' => 'etf',
                'symbol_name' => 'ETFテスト銘柄',
            ]);
            femdHoldingSnapshot($snapshot, $etfHolding, ['unrealized_gain_rate' => 5.0]);

            $mutualFundHolding = femdHolding([
                'symbol_code' => '楽天・全米株式インデックス・ファンド',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
                'symbol_name' => '楽天・全米株式インデックス・ファンド',
            ]);
            femdHoldingSnapshot($snapshot, $mutualFundHolding, ['unrealized_gain_rate' => 5.0]);

            $action = femdAction(
                new FakeJpStockPriceClient(),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            $this->assertDatabaseMissing('technical_indicators', ['holding_id' => $etfHolding->id]);
            $this->assertDatabaseMissing('technical_indicators', ['holding_id' => $mutualFundHolding->id]);
        });
    });

    describe('市場全体指標（Phase1先行実装）', function () {
        test('保有銘柄が対象外のみ、あるいは0件でもmarket_indicator_snapshotsにnikkei225/sp500が保存される', function () {
            [$batch, $snapshot] = femdImportBatch();
            // Deliberately no holdings at all: step 3 (市場全体指標) must not
            // be gated on the presence of any eligible stock holding.

            $nikkeiCloses = femdCloses(30000.0, 100.0, 30);
            $sp500Closes = femdCloses(4500.0, 20.0, 30);
            $nikkeiHistory = femdPriceHistory($nikkeiCloses, 1_000_000, '2023-01-02');
            $sp500History = femdPriceHistory($sp500Closes, 1_000_000, '2023-01-02');

            $action = femdAction(
                new FakeJpStockPriceClient(),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient(['nikkei225' => $nikkeiHistory, 'sp500' => $sp500History]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            $this->assertDatabaseHas('market_indicator_snapshots', [
                'snapshot_id' => $snapshot->id,
                'index_name' => 'nikkei225',
            ]);
            $this->assertDatabaseHas('market_indicator_snapshots', [
                'snapshot_id' => $snapshot->id,
                'index_name' => 'sp500',
            ]);
            expect(DB::table('market_indicator_snapshots')->where('snapshot_id', $snapshot->id)->count())->toBe(2);

            $nikkeiRow = DB::table('market_indicator_snapshots')->where('snapshot_id', $snapshot->id)->where('index_name', 'nikkei225')->first();
            expect((float) $nikkeiRow->value)->toEqualWithDelta(end($nikkeiCloses), 0.01);
            $expectedNikkeiChangeRate = (($nikkeiCloses[29] - $nikkeiCloses[28]) / $nikkeiCloses[28]) * 100;
            expect((float) $nikkeiRow->change_rate)->toEqualWithDelta($expectedNikkeiChangeRate, 0.01);
            // ma_deviation's exact MA period is an open assumption — see file docblock.
            expect($nikkeiRow->ma_deviation)->not->toBeNull();

            $sp500Row = DB::table('market_indicator_snapshots')->where('snapshot_id', $snapshot->id)->where('index_name', 'sp500')->first();
            expect((float) $sp500Row->value)->toEqualWithDelta(end($sp500Closes), 0.01);
            $expectedSp500ChangeRate = (($sp500Closes[29] - $sp500Closes[28]) / $sp500Closes[28]) * 100;
            expect((float) $sp500Row->change_rate)->toEqualWithDelta($expectedSp500ChangeRate, 0.01);
        });
    });

    describe('セクター平均騰落率', function () {
        test('同一セクターに属する複数銘柄のrelative_strength_vs_sectorがセクター内平均騰落率を基準に算出される', function () {
            [$batch, $snapshot] = femdImportBatch();

            $holdingA = femdHolding(['symbol_code' => '6758', 'symbol_name' => 'ソニーグループ']);
            femdHoldingSnapshot($snapshot, $holdingA, ['unrealized_gain_rate' => 5.0]);

            $holdingB = femdHolding(['symbol_code' => '6501', 'symbol_name' => '日立製作所']);
            femdHoldingSnapshot($snapshot, $holdingB, ['unrealized_gain_rate' => 5.0]);

            $priceHistoryA = femdPriceHistory(femdCloses(3000.0, 5.0, 20));
            $priceHistoryB = femdPriceHistory(femdCloses(1000.0, 8.0, 20));
            $nikkeiHistory = femdPriceHistory(femdCloses(30000.0, 100.0, 20));

            $sectorInfo = ['code' => '3600', 'name' => '電気機器'];

            $action = femdAction(
                new FakeJpStockPriceClient(['6758' => $priceHistoryA, '6501' => $priceHistoryB]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient(['nikkei225' => $nikkeiHistory, 'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20))]),
                new FakeJQuantsClient(['6758' => $sectorInfo, '6501' => $sectorInfo]),
            );

            $action->execute($batch);

            $returnA = femd13wReturn($priceHistoryA);
            $returnB = femd13wReturn($priceHistoryB);
            $sectorAverage13w = ($returnA + $returnB) / 2;
            $marketReturn13w = femd13wReturn($nikkeiHistory);

            $expectedA = (new TechnicalIndicatorCalculator)->calculate($priceHistoryA, $marketReturn13w, $sectorAverage13w);
            $expectedB = (new TechnicalIndicatorCalculator)->calculate($priceHistoryB, $marketReturn13w, $sectorAverage13w);

            $rowA = DB::table('technical_indicators')->where('holding_id', $holdingA->id)->first();
            $rowB = DB::table('technical_indicators')->where('holding_id', $holdingB->id)->first();

            expect((float) $rowA->relative_strength_vs_sector)->toEqualWithDelta($expectedA['relative_strength_vs_sector'], 0.01);
            expect((float) $rowB->relative_strength_vs_sector)->toEqualWithDelta($expectedB['relative_strength_vs_sector'], 0.01);
        });
    });

    describe('利確シグナル判定（unrealized_gain_rate > 20%のみ対象）', function () {
        test('unrealized_gain_rateが20%超の銘柄はシグナル判定が行われ該当signalsが保存される', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding(['symbol_code' => '7203']);
            $holdingSnapshot = femdHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 25.0]);

            // 19週横ばい(100)->最終週130まで急騰。bb_upper≈114.92 < 130 (bollinger_overheat発生、
            // tests/Unit/Services/Analysis/SignalDeterminationServiceTest.php で検証済みの
            // フィクスチャと同一のもの)。出来高一定のためvolume_spike_declineは発生しない。
            $closes = array_merge(array_fill(0, 19, 100.0), [130.0]);
            $priceHistory = femdPriceHistory($closes, 1000);

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            $signals = Signal::where('holding_snapshot_id', $holdingSnapshot->id)->get();
            expect($signals)->toHaveCount(1);
            expect($signals->first()->signal_type)->toBe('bollinger_overheat');
        });

        test('unrealized_gain_rateがちょうど20%（20%超えていない）の銘柄はシグナル判定自体行われずsignalsが保存されない（境界値）', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding(['symbol_code' => '7203']);
            $holdingSnapshot = femdHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 20.0]);

            // シグナル判定が(誤って)行われれば bollinger_overheat が発生するはずの
            // フィクスチャをあえて使い、gain_rateのゲートが機能していることを強く検証する。
            $closes = array_merge(array_fill(0, 19, 100.0), [130.0]);
            $priceHistory = femdPriceHistory($closes, 1000);

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            expect(Signal::where('holding_snapshot_id', $holdingSnapshot->id)->count())->toBe(0);
        });
    });

    describe('シグナルの再判定（再実行時に成立しなくなった古いシグナルの削除）', function () {
        test('1回目のexecute()でbollinger_overheatが発生した後、2回目のexecute()でどのシグナルも発生しない価格推移に差し替えて再実行すると、1回目のシグナル行が削除されsignalsが0件になる', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding(['symbol_code' => '7203']);
            $holdingSnapshot = femdHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 25.0]);

            $marketIndexHistoryForOverheatRun = new FakeMarketIndexClient([
                'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
            ]);

            // 1回目: 「利確シグナル判定」ブロックと同一のbollinger_overheatフィクスチャ
            // (19週横ばい(100)→最終週130まで急騰、bb_upper≈114.92<130) でシグナルを発生させる。
            $overheatCloses = array_merge(array_fill(0, 19, 100.0), [130.0]);
            $overheatPriceHistory = femdPriceHistory($overheatCloses, 1000);

            $firstAction = femdAction(
                new FakeJpStockPriceClient(['7203' => $overheatPriceHistory]),
                new FakeUsStockPriceClient(),
                $marketIndexHistoryForOverheatRun,
                new FakeJQuantsClient(),
            );

            $firstAction->execute($batch);

            expect(Signal::where('holding_snapshot_id', $holdingSnapshot->id)->where('signal_type', 'bollinger_overheat')->count())->toBe(1);

            // 2回目: 同一batch・同一snapshot・同一holding_snapshotに対して、
            // 80週連続の緩やかな上昇のみ（どのシグナルも発生しない価格推移）に
            // Fakeクライアントを差し替えて再実行する（外部API障害後のリトライ等を想定）。
            // 市場指数は13週に満たない短い履歴にしてmarketReturn13wをnullにし、
            // relative_strength_vs_marketがnullになる（=relative_strength_weakening
            // シグナルが誤って発生しない）ようにしている。
            $mildPriceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 80));

            $secondAction = femdAction(
                new FakeJpStockPriceClient(['7203' => $mildPriceHistory]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 10)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 10)),
                ]),
                new FakeJQuantsClient(),
            );

            $secondAction->execute($batch);

            expect(Signal::where('holding_snapshot_id', $holdingSnapshot->id)->count())->toBe(0);
        });
    });

    describe('個別銘柄の失敗が全体を止めない', function () {
        test('価格取得に失敗した銘柄はスキップされ、他の銘柄の処理は正常に完了する', function () {
            [$batch, $snapshot] = femdImportBatch();

            $failingHolding = femdHolding(['symbol_code' => '9999', 'symbol_name' => '取得失敗銘柄']);
            $failingSnapshot = femdHoldingSnapshot($snapshot, $failingHolding, ['unrealized_gain_rate' => 30.0]);

            $okHolding = femdHolding(['symbol_code' => '1234', 'symbol_name' => '正常銘柄']);
            femdHoldingSnapshot($snapshot, $okHolding, ['unrealized_gain_rate' => 10.0]);

            $okPriceHistory = femdPriceHistory(femdCloses(1000.0, 3.0, 80));

            $action = femdAction(
                new FakeJpStockPriceClient(
                    responses: ['1234' => $okPriceHistory],
                    throwsFor: ['9999'],
                ),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            $this->assertDatabaseMissing('technical_indicators', ['holding_id' => $failingHolding->id]);
            expect(Signal::where('holding_snapshot_id', $failingSnapshot->id)->count())->toBe(0);

            $this->assertDatabaseHas('technical_indicators', ['holding_id' => $okHolding->id]);
        });
    });

    describe('UPSERT（再実行時の更新）', function () {
        test('既存のtechnical_indicators行がある銘柄に対して再実行すると、新規行を追加せず既存行が更新される', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding(['symbol_code' => '5001', 'symbol_name' => 'テスト銘柄']);
            femdHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 5.0]);

            $staleComputedAt = now()->subDays(30);
            TechnicalIndicator::create([
                'holding_id' => $holding->id,
                'rsi' => 1.11,
                'macd' => null,
                'macd_signal' => null,
                'ma20' => 2.22,
                'ma75' => null,
                'bb_upper' => null,
                'bb_lower' => null,
                'computed_at' => $staleComputedAt,
            ]);

            // 80週連続上昇のみ（下落なし）-> avg_loss=0 -> RSI=100.0（決定的、
            // TechnicalIndicatorCalculatorのRSI計算式より導出。既存のstale値1.11とは
            // 明確に異なる値になる）。
            $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 80));

            $action = femdAction(
                new FakeJpStockPriceClient(['5001' => $priceHistory]),
                new FakeUsStockPriceClient(),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient(),
            );

            $action->execute($batch);

            expect(TechnicalIndicator::where('holding_id', $holding->id)->count())->toBe(1);

            $row = TechnicalIndicator::where('holding_id', $holding->id)->first();
            expect((float) $row->rsi)->toEqualWithDelta(100.0, 0.01);
            expect($row->computed_at->greaterThan($staleComputedAt))->toBeTrue();
        });
    });
});
