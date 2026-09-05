<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Models\FinancialStatement;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\FundamentalIndicatorMapper;
use App\Services\Analysis\TechnicalIndicatorCalculator;
use App\Services\Analysis\UsFundamentalIndicatorMapper;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fakes\FakeFinnhubClient;
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
|--------------------------------------------------------------------------
| ADR-0009 addendum (2026-09-05, Red phase): US-stock Finnhub fundamentals
|--------------------------------------------------------------------------
|
| Source of truth: docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md — US
| holdings now also get `fundamental_indicators` populated (via a new
| FinnhubClientInterface + UsFundamentalIndicatorMapper), which this file's
| pre-existing "正常系（US株）" test asserted the *opposite* of
| (`assertDatabaseMissing('fundamental_indicators', ...)`). That assertion
| predates ADR-0009 and is now factually superseded by it, so — unlike every
| other pre-existing test in this file, which is left untouched — that one
| test's body has been updated in place (renamed + rewritten) to assert the
| new, ADR-0009-correct behavior. This is *not* a "JP test change" (the
| instruction to leave existing JP cases untouched does not apply to it);
| flag at Gate 4 if the reviewer would prefer keeping the old assertion
| alongside a new, separately-named test instead of rewriting in place.
|
| femdAction()'s signature gains a new, OPTIONAL 5th parameter
| (`?FakeFinnhubClient $finnhubClient = null`), bound only when explicitly
| passed. This is a deliberate compromise, flagged here for Gate 4 review:
|   - Deliberately kept optional (rather than mirroring the other 4 Fakes,
|     which are all required positional params) so that none of the
|     pre-existing call sites in this file need to change — required so
|     "既存のテスト（JP側）が引き続きGreenであることも確認すること" holds
|     *right now*, in the Red phase (this file's existing tests must not
|     start failing merely because this new param was threaded through).
|   - Known consequence for the Green-phase implementer: once
|     FetchExternalMarketDataAction's constructor gains a *required*
|     FinnhubClientInterface dependency (per the task description, no
|     default), any test that resolves the Action via femdAction() without
|     passing an explicit FakeFinnhubClient will hit an unresolvable-
|     interface container error, because femdAction() only calls
|     `app()->instance(FinnhubClientInterface::class, ...)` when
|     $finnhubClient is non-null. The Green-phase implementer will very
|     likely need to change femdAction() to bind a default
|     `new FakeFinnhubClient` when $finnhubClient is null, so every
|     pre-existing test in this file keeps passing once the new
|     constructor dependency lands — this is intentionally left as an open
|     question for Gate 4 rather than pre-emptively "fixed" here, since
|     eagerly constructing `new FakeFinnhubClient` unconditionally today
|     would itself break every pre-existing test right now (FakeFinnhubClient
|     implements FinnhubClientInterface, which doesn't exist yet, so merely
|     instantiating it triggers a fatal "Interface not found" error).
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
 * Same 5-period fixture shape as femdStatements(), but with an EPS swing
 * (past EPS near-zero → current EPS much larger) that produces a growth
 * rate exceeding fundamental_indicators.eps_growth's current
 * decimal(7,4) column width (max ±999.9999%). Mirrors a real production
 * row found while importing docs/original-docs/assetbalance*.csv (a JP
 * holding recovering from a near-zero EPS, ~1136% growth) — see
 * describe('ADR-0004 再発防止: 実データ由来のバグの回帰テスト') below.
 *
 * @return array<int, array{disclosed_date: string, net_sales: float|null, operating_profit: float|null, profit: float|null, eps: float|null, book_value_per_share: float|null, equity_to_asset_ratio: float|null, roe: float|null, dividend_per_share_annual: float|null, payout_ratio_annual: float|null}>
 */
function femdStatementsWithExtremeEpsGrowth(): array
{
    $statements = femdStatements();

    // (108.8 - 8.8) / 8.8 * 100 ≈ 1136.36% — comfortably over the
    // decimal(7,4) max of 999.9999, and not a "round" number so a coincidental
    // rounding/truncation in the buggy column width wouldn't accidentally
    // mask the failure.
    $statements[0]['eps'] = 108.8;
    $statements[1]['eps'] = 108.8;
    $statements[2]['eps'] = 108.8;
    $statements[3]['eps'] = 108.8;
    $statements[4]['eps'] = 8.8;

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
 * Binds the MarketData Fakes into the container and resolves the Action
 * under test. This call itself is the expected Red-state trigger (see
 * file-level docblock): App\Actions\Analysis\FetchExternalMarketDataAction
 * does not exist yet.
 *
 * $finnhubClient (ADR-0009 addendum, see file-level docblock) is
 * deliberately optional and only bound when explicitly passed, so none of
 * this file's pre-existing call sites (which don't pass it) need to change
 * — see the file-level docblock's "ADR-0009 addendum" section for the
 * Gate-4-flagged consequence of this choice.
 */
function femdAction(
    FakeJpStockPriceClient $jpStockPriceClient,
    FakeUsStockPriceClient $usStockPriceClient,
    FakeMarketIndexClient $marketIndexClient,
    FakeJQuantsClient $jQuantsClient,
    ?FakeFinnhubClient $finnhubClient = null,
): FetchExternalMarketDataAction {
    app()->instance(JpStockPriceClientInterface::class, $jpStockPriceClient);
    app()->instance(UsStockPriceClientInterface::class, $usStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, $marketIndexClient);
    app()->instance(JQuantsClientInterface::class, $jQuantsClient);

    app()->instance(FinnhubClientInterface::class, $finnhubClient ?? new FakeFinnhubClient);

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
                new FakeUsStockPriceClient,
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
                new FakeUsStockPriceClient,
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
        /*
        |----------------------------------------------------------------
        | ADR-0009 (2026-09-05): this test previously asserted
        | `assertDatabaseMissing('fundamental_indicators', ...)` for US
        | holdings. ADR-0009 supersedes that — US holdings now also get
        | `fundamental_indicators` populated via Finnhub — so this test has
        | been rewritten in place (see the file-level "ADR-0009 addendum"
        | docblock section for why this one pre-existing test, unlike every
        | other one in this file, is not left untouched).
        |----------------------------------------------------------------
        */
        test('US個別株はテクニカル指標に加え、Finnhubから取得したファンダメンタルズ指標も保存される（セクター分類は引き続き対象外、ADR-0009）', function () {
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

            // Real, HTTP-verified Finnhub AAPL values (per ADR-0009 /
            // task description). reportedFinancials[1].operating_income is
            // an invented-but-consistent fixture value (only equity_ratio's
            // inputs were pinned to real data by the task description).
            $metrics = [
                'peTTM' => 37.3169,
                'pbAnnual' => 50.978,
                'roeTTM' => 137.18,
                'revenueGrowthTTMYoy' => 14.24,
                'epsGrowthTTMYoy' => 32.61,
                'dividendYieldIndicatedAnnual' => 0.50534,
                'payoutRatioTTM' => 12.13,
                'pegTTM' => 2.93443,
            ];
            $reportedFinancials = [
                ['operating_income' => 100000000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
                ['operating_income' => 80000000000.0, 'total_assets' => 352755000000.0, 'total_equity' => 56950000000.0],
            ];

            $action = femdAction(
                new FakeJpStockPriceClient,
                new FakeUsStockPriceClient(['AAPL' => $priceHistory]),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => $sp500History,
                ]),
                new FakeJQuantsClient,
                new FakeFinnhubClient(['AAPL' => $metrics], ['AAPL' => $reportedFinancials]),
            );

            $action->execute($batch);

            $marketReturn13w = femd13wReturn($sp500History);
            $expectedTechnical = (new TechnicalIndicatorCalculator)->calculate($priceHistory, $marketReturn13w, null);
            femdAssertTechnicalIndicatorMatches($holding->id, $expectedTechnical);

            $expectedFundamental = (new UsFundamentalIndicatorMapper)->map($metrics, $reportedFinancials);
            femdAssertFundamentalIndicatorMatches($holding->id, $expectedFundamental);

            // financial_statements（UC-006向け）はADR-0009のスコープ外のまま。
            $this->assertDatabaseMissing('financial_statements', ['holding_id' => $holding->id]);

            // セクター分類はJP株限定のロジック（J-Quants由来）のままで、
            // Finnhub統合はここに影響しない。
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
                new FakeJpStockPriceClient,
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient,
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
                new FakeJpStockPriceClient,
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient(['nikkei225' => $nikkeiHistory, 'sp500' => $sp500History]),
                new FakeJQuantsClient,
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
                new FakeUsStockPriceClient,
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
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient,
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
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient,
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
                new FakeUsStockPriceClient,
                $marketIndexHistoryForOverheatRun,
                new FakeJQuantsClient,
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
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 10)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 10)),
                ]),
                new FakeJQuantsClient,
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
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient,
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
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                ]),
                new FakeJQuantsClient,
            );

            $action->execute($batch);

            expect(TechnicalIndicator::where('holding_id', $holding->id)->count())->toBe(1);

            $row = TechnicalIndicator::where('holding_id', $holding->id)->first();
            expect((float) $row->rsi)->toEqualWithDelta(100.0, 0.01);
            expect($row->computed_at->greaterThan($staleComputedAt))->toBeTrue();
        });
    });

    describe('financial_statements（UC-006向け財務諸表履歴）の保存', function () {
        /*
        |----------------------------------------------------------------
        | Source of truth for this describe() block:
        |   - docs/architecture/data-model.md ("financial_statements"
        |     section) — columns, `(holding_id, fiscal_period)` unique
        |     index, "同一期を重複INSERTしない" UPSERT-by-fiscal_period rule.
        |   - C:\Users\minow\.claude\plans\stock_auto_order-uc006-implementation-phase.md
        |     (Cycle A: "既存のFetchExternalMarketDataAction ... を改修し、
        |     JP株についてjQuantsClient->fetchStatements()の結果を
        |     FinancialStatement::updateOrCreate()で5期分保存する。
        |     revenue_yoy_change/operating_income_yoy_changeはFundamentalIndicatorMapper
        |     ::calculateGrowth()と同じ「4期前とのYoY」ロジックを流用").
        |
        | Expected Red state: App\Models\FinancialStatement does not exist
        | yet (no file at app/Models/FinancialStatement.php, no migration
        | under database/migrations/ for a `financial_statements` table —
        | verified via Glob before writing these tests). Every test below
        | is expected to fail with a fatal "Class \"App\Models\
        | FinancialStatement\" not found" error, raised either directly
        | (tests that construct a pre-existing row via
        | FinancialStatement::create() before calling execute()) or
        | indirectly inside FetchExternalMarketDataAction::execute() itself
        | once it's extended to reference the model (not yet the case
        | today — see note below). Same convention as
        | tests/Unit/Services/Import/Support/AccountTypeMapperTest.php for
        | a not-yet-existing class, and tests/Feature/UC003HoldingDetailTest.php
        | for HoldingMemo before that model existed.
        |
        | Important nuance verified by reading the current (Green)
        | FetchExternalMarketDataAction::execute() before writing this
        | block: it does NOT yet write to `financial_statements` at all
        | (only `technical_indicators`/`fundamental_indicators`/`signals`/
        | `market_indicator_snapshots`). So today, tests 1〜4 below (which
        | only assert on FinancialStatement rows *after* calling
        | execute()) are Red purely because the `FinancialStatement`
        | class-reference inside the test itself (in the assertion, via
        | `FinancialStatement::where(...)`) fails to resolve — execute()
        | runs to completion without ever touching financial_statements.
        | Test 5 (UPSERT) additionally constructs a FinancialStatement row
        | *before* calling execute(), so it fails even earlier, at the
        | Arrange step. Once Green-phase work adds the migration + model +
        | the write path inside execute()'s per-holding loop, all 5
        | assertions below become meaningful.
        |
        | Assumption flagged for Gate 4 review: the plan says
        | revenue_yoy_change/operating_income_yoy_change for index 0 only
        | reuse FundamentalIndicatorMapper::calculateGrowth()'s exact
        | formula (index 0 vs index 4, null if either is missing or the
        | index-4 value is 0), and index 1〜4 are always null (the 5-period
        | fetch window can't look back a further 4 periods for those). This
        | test pins that contract down explicitly since data-model.md's
        | column description alone ("売上高前年比増減") doesn't specify which
        | rows get a computed value vs null.
        |----------------------------------------------------------------
        */

        test('JP個別株の保有銘柄について、5期分の財務諸表がfinancial_statementsにfiscal_period・revenue・operating_income・epsとともに全て保存される', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => 'トヨタ自動車',
            ]);
            femdHoldingSnapshot($snapshot, $holding, [
                'current_price' => 2500.0,
                'unrealized_gain_rate' => 5.0, // <=20% -> シグナル判定の副作用を避ける
            ]);

            $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));
            $statements = femdStatements();

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient(statementsResponses: ['7203' => $statements]),
            );

            $action->execute($batch);

            expect(FinancialStatement::where('holding_id', $holding->id)->count())->toBe(5);

            foreach ($statements as $statement) {
                $row = FinancialStatement::where('holding_id', $holding->id)
                    ->where('fiscal_period', $statement['disclosed_date'])
                    ->first();

                expect($row)->not->toBeNull();
                expect((float) $row->revenue)->toEqualWithDelta((float) $statement['net_sales'], 0.01);
                expect((float) $row->operating_income)->toEqualWithDelta((float) $statement['operating_profit'], 0.01);
                expect((float) $row->eps)->toEqualWithDelta((float) $statement['eps'], 0.01);
            }
        });

        test('最新期（index 0）のrevenue_yoy_change・operating_income_yoy_changeが、4期前（index4）との比較で正しく算出される', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => 'トヨタ自動車',
            ]);
            femdHoldingSnapshot($snapshot, $holding, [
                'current_price' => 2500.0,
                'unrealized_gain_rate' => 5.0,
            ]);

            $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));
            // femdStatements(): index0 net_sales=120000/operating_profit=15000,
            // index4 net_sales=100000/operating_profit=12000 ->
            // revenue_yoy_change=(120000-100000)/100000*100=20%,
            // operating_income_yoy_change=(15000-12000)/12000*100=25%
            // (identical formula/inputs to
            // FundamentalIndicatorMapper::calculateGrowth()).
            $statements = femdStatements();

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient(statementsResponses: ['7203' => $statements]),
            );

            $action->execute($batch);

            $latestRow = FinancialStatement::where('holding_id', $holding->id)
                ->where('fiscal_period', $statements[0]['disclosed_date'])
                ->first();

            expect($latestRow)->not->toBeNull();
            expect((float) $latestRow->revenue_yoy_change)->toEqualWithDelta(20.0, 0.01);
            expect((float) $latestRow->operating_income_yoy_change)->toEqualWithDelta(25.0, 0.01);
        });

        test('過去の期（index 1〜4）のrevenue_yoy_change・operating_income_yoy_changeはnullになる（5期分の取得データだけでは4期前を遡れないため）', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => 'トヨタ自動車',
            ]);
            femdHoldingSnapshot($snapshot, $holding, [
                'current_price' => 2500.0,
                'unrealized_gain_rate' => 5.0,
            ]);

            $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));
            $statements = femdStatements();

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient(statementsResponses: ['7203' => $statements]),
            );

            $action->execute($batch);

            for ($i = 1; $i <= 4; $i++) {
                $row = FinancialStatement::where('holding_id', $holding->id)
                    ->where('fiscal_period', $statements[$i]['disclosed_date'])
                    ->first();

                expect($row)->not->toBeNull();
                expect($row->revenue_yoy_change)->toBeNull();
                expect($row->operating_income_yoy_change)->toBeNull();
            }
        });

        test('US株の保有銘柄についてはfinancial_statementsが一切保存されない', function () {
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

            $priceHistory = femdPriceHistory(femdCloses(150.0, 2.0, 20));

            $action = femdAction(
                new FakeJpStockPriceClient,
                new FakeUsStockPriceClient(['AAPL' => $priceHistory]),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient,
            );

            $action->execute($batch);

            expect(FinancialStatement::where('holding_id', $holding->id)->count())->toBe(0);
        });

        test('既存のfinancial_statements行がある場合、同一(holding_id, fiscal_period)には新規行を追加せず既存行が更新される（UPSERT）', function () {
            [$batch, $snapshot] = femdImportBatch();
            $holding = femdHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => 'トヨタ自動車',
            ]);
            femdHoldingSnapshot($snapshot, $holding, [
                'current_price' => 2500.0,
                'unrealized_gain_rate' => 5.0,
            ]);

            $statements = femdStatements();
            $staleFetchedAt = now()->subDays(30);

            // Pre-existing row for the latest fiscal_period, with values
            // distinctly different from the fixture's real figures (120000/
            // 15000/120), so a passing re-run is unambiguous.
            FinancialStatement::create([
                'holding_id' => $holding->id,
                'fiscal_period' => $statements[0]['disclosed_date'],
                'revenue' => 1.0,
                'operating_income' => 1.0,
                'eps' => 1.0,
                'revenue_yoy_change' => null,
                'operating_income_yoy_change' => null,
                'fetched_at' => $staleFetchedAt,
            ]);

            $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));

            $action = femdAction(
                new FakeJpStockPriceClient(['7203' => $priceHistory]),
                new FakeUsStockPriceClient,
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient(statementsResponses: ['7203' => $statements]),
            );

            $action->execute($batch);

            // Still exactly one row per fiscal_period across all 5 periods
            // (no duplicate inserted for the pre-existing one).
            expect(FinancialStatement::where('holding_id', $holding->id)->count())->toBe(5);

            $updatedRow = FinancialStatement::where('holding_id', $holding->id)
                ->where('fiscal_period', $statements[0]['disclosed_date'])
                ->first();

            expect((float) $updatedRow->revenue)->toEqualWithDelta(120000.0, 0.01);
            expect((float) $updatedRow->operating_income)->toEqualWithDelta(15000.0, 0.01);
            expect((float) $updatedRow->eps)->toEqualWithDelta(120.0, 0.01);
            expect($updatedRow->fetched_at->greaterThan($staleFetchedAt))->toBeTrue();
        });
    });

    describe('ADR-0004 再発防止: 実データ由来のバグの回帰テスト', function () {
        /*
        |----------------------------------------------------------------
        | Background (see task description for this Red-phase addition):
        | Importing the real user's 134-holding CSV
        | (docs/original-docs/assetbalance*.csv) through UC-001 surfaced
        | two real bugs:
        |   1. fundamental_indicators.eps_growth/revenue_growth/
        |      operating_income_growth are decimal(7,4) (max ±999.9999%),
        |      but a real JP holding recovering from a near-zero EPS
        |      produced ~1136% growth, causing MySQL's strict mode to
        |      raise "Out of range value" on INSERT.
        |   2. That per-holding DB failure (or any other per-holding
        |      failure inside FetchExternalMarketDataAction::execute()'s
        |      second loop, or inside the first loop's fetchSectorInfo()
        |      call) is not caught individually, unlike the first loop's
        |      price-history fetch (already covered by
        |      describe('個別銘柄の失敗が全体を止めない') above). This
        |      aborts the *entire* batch — every holding processed after
        |      the failing one silently gets no technical/fundamental
        |      indicators or signals, and ImportCsvAction swallows the
        |      exception without logging it.
        |----------------------------------------------------------------
        */

        describe('eps_growthのdecimal桁数超過（Out of Range）', function () {
            test('EPS成長率が1000%を超える銘柄でも fundamental_indicators に丸めなしの正しい値が保存される', function () {
                [$batch, $snapshot] = femdImportBatch();
                $holding = femdHolding([
                    'symbol_code' => '3999',
                    'symbol_name' => 'EPS急回復銘柄',
                ]);
                femdHoldingSnapshot($snapshot, $holding, [
                    'current_price' => 2500.0,
                    'unrealized_gain_rate' => 5.0, // <=20% -> シグナル判定の副作用を避ける
                ]);

                $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));
                $statements = femdStatementsWithExtremeEpsGrowth();

                $action = femdAction(
                    new FakeJpStockPriceClient(['3999' => $priceHistory]),
                    new FakeUsStockPriceClient,
                    new FakeMarketIndexClient([
                        'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                        'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                    ]),
                    new FakeJQuantsClient(statementsResponses: ['3999' => $statements]),
                );

                $expectedFundamental = (new FundamentalIndicatorMapper)->map($statements, 2500.0);
                // Sanity check on the fixture itself: this test is only
                // meaningful if the growth rate actually exceeds the
                // buggy decimal(7,4) column's range.
                expect($expectedFundamental['eps_growth'])->toBeGreaterThan(999.9999);

                $action->execute($batch);

                femdAssertFundamentalIndicatorMatches($holding->id, $expectedFundamental);
            });
        });

        describe('financial_statements.revenue/operating_incomeのNOT NULL制約とJ-Quantsのnull値の衝突', function () {
            /*
            |------------------------------------------------------------
            | /reviewの拡張レベル指摘（UC-006 Cycle A、2026-08-23、MEDIUM）:
            | JQuantsClient::fetchStatements()のnet_sales/operating_profit
            | はtoFloatOrNull()経由でfloat|nullとして返され、
            | FundamentalIndicatorMapperはこのnullを一貫して考慮済みだが、
            | financial_statements.revenue/operating_incomeはNOT NULLの
            | ままだった。この書き込みはDB::transaction()配下・外側の
            | try-catch配下にあるため、いずれかの期でnet_sales/
            | operating_profitがnullの銘柄は、financial_statementsの
            | INSERTでQueryExceptionが発生し、同一銘柄の
            | technical_indicators/fundamental_indicators/signals更新まで
            | 巻き添えでロールバックされてしまっていた（修正前は本テストが
            | Redになることで再現する）。
            |------------------------------------------------------------
            */

            test('net_sales/operating_profitがnullの期があっても、financial_statementsにはnullのまま保存され、同一銘柄のtechnical_indicators更新はロールバックされない', function () {
                [$batch, $snapshot] = femdImportBatch();
                $holding = femdHolding([
                    'symbol_code' => '7203',
                    'market' => 'jp',
                    'instrument_type' => 'stock',
                    'symbol_name' => 'トヨタ自動車',
                ]);
                femdHoldingSnapshot($snapshot, $holding, [
                    'current_price' => 2500.0,
                    'unrealized_gain_rate' => 5.0,
                ]);

                $priceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 20));
                $statements = femdStatements();
                // 決算様式の違い等でJ-Quantsが当該期のSales/OPを欠損させて
                // 返すケースを再現する（過去期・index2）。
                $statements[2]['net_sales'] = null;
                $statements[2]['operating_profit'] = null;

                $action = femdAction(
                    new FakeJpStockPriceClient(['7203' => $priceHistory]),
                    new FakeUsStockPriceClient,
                    new FakeMarketIndexClient([
                        'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                        'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                    ]),
                    new FakeJQuantsClient(statementsResponses: ['7203' => $statements]),
                );

                $action->execute($batch);

                // financial_statementsは5期分すべて保存され、null値の期は
                // nullのまま保持される（例外で欠落しない）。
                expect(FinancialStatement::where('holding_id', $holding->id)->count())->toBe(5);

                $nullPeriodRow = FinancialStatement::where('holding_id', $holding->id)
                    ->where('fiscal_period', $statements[2]['disclosed_date'])
                    ->first();

                expect($nullPeriodRow)->not->toBeNull();
                expect($nullPeriodRow->revenue)->toBeNull();
                expect($nullPeriodRow->operating_income)->toBeNull();

                // financial_statementsの書き込み失敗が同一トランザクション内の
                // 他の更新を道連れにしていないことを確認する。
                $this->assertDatabaseHas('technical_indicators', ['holding_id' => $holding->id]);
                $this->assertDatabaseHas('fundamental_indicators', ['holding_id' => $holding->id]);
            });
        });

        describe('per-holding例外分離が2つ目のループ・fetchSectorInfoに及んでいない', function () {
            test('fetchSectorInfo()が例外を投げても、その銘柄はスキップされ他の銘柄の処理は継続する', function () {
                [$batch, $snapshot] = femdImportBatch();

                // 先に処理される銘柄（symbol_codeが辞書順で小さくID順でも先）で
                // fetchSectorInfo()を失敗させる。1つ目のループの価格取得
                // try-catchの「外」で例外が起きるため、現状の実装では
                // execute()全体が例外を投げて中断する。
                $failingHolding = femdHolding([
                    'symbol_code' => '8888',
                    'symbol_name' => 'セクター取得失敗銘柄',
                ]);
                femdHoldingSnapshot($snapshot, $failingHolding, ['unrealized_gain_rate' => 5.0]);

                $okHolding = femdHolding([
                    'symbol_code' => '1234',
                    'symbol_name' => '正常銘柄',
                ]);
                femdHoldingSnapshot($snapshot, $okHolding, ['unrealized_gain_rate' => 10.0]);

                $failingPriceHistory = femdPriceHistory(femdCloses(500.0, 2.0, 20));
                $okPriceHistory = femdPriceHistory(femdCloses(1000.0, 3.0, 20));

                $action = femdAction(
                    new FakeJpStockPriceClient([
                        '8888' => $failingPriceHistory,
                        '1234' => $okPriceHistory,
                    ]),
                    new FakeUsStockPriceClient,
                    new FakeMarketIndexClient([
                        'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                        'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                    ]),
                    new FakeJQuantsClient(throwsForSectorInfo: ['8888']),
                );

                $action->execute($batch);

                $this->assertDatabaseMissing('technical_indicators', ['holding_id' => $failingHolding->id]);
                $this->assertDatabaseHas('technical_indicators', ['holding_id' => $okHolding->id]);
            });

            test('2つ目のループ内（テクニカル・ファンダメンタルズ指標計算やDB保存）で例外が起きても、その銘柄はスキップされ他の銘柄の処理は継続する', function () {
                [$batch, $snapshot] = femdImportBatch();

                // 先に処理される銘柄でfetchStatements()（2つ目のループ内、
                // ファンダメンタルズ指標計算の直前に呼ばれる）を失敗させる。
                // 現状の実装では2つ目のループにper-holdingのtry-catchが
                // 一切ないため、この銘柄以降（okHoldingを含む）の
                // テクニカル指標・ファンダメンタルズ指標・シグナルが
                // 一切保存されないまま処理が中断する。
                $failingHolding = femdHolding([
                    'symbol_code' => '7777',
                    'symbol_name' => '決算取得失敗銘柄',
                ]);
                femdHoldingSnapshot($snapshot, $failingHolding, ['unrealized_gain_rate' => 5.0]);

                $okHolding = femdHolding([
                    'symbol_code' => '1234',
                    'symbol_name' => '正常銘柄',
                ]);
                femdHoldingSnapshot($snapshot, $okHolding, ['unrealized_gain_rate' => 10.0]);

                $failingPriceHistory = femdPriceHistory(femdCloses(500.0, 2.0, 20));
                $okPriceHistory = femdPriceHistory(femdCloses(1000.0, 3.0, 20));

                $action = femdAction(
                    new FakeJpStockPriceClient([
                        '7777' => $failingPriceHistory,
                        '1234' => $okPriceHistory,
                    ]),
                    new FakeUsStockPriceClient,
                    new FakeMarketIndexClient([
                        'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                        'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                    ]),
                    new FakeJQuantsClient(throwsForStatements: ['7777']),
                );

                $action->execute($batch);

                $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $failingHolding->id]);
                $this->assertDatabaseHas('technical_indicators', ['holding_id' => $okHolding->id]);
                $this->assertDatabaseHas('fundamental_indicators', ['holding_id' => $okHolding->id]);
            });

            test('2つ目のループ内でテクニカル指標保存後に例外が起きた銘柄は、テクニカル指標も含めて更新前の状態にロールバックされる（1銘柄単位のアトミック性）', function () {
                [$batch, $snapshot] = femdImportBatch();

                $failingHolding = femdHolding(['symbol_code' => '7777', 'symbol_name' => '決算取得失敗銘柄']);
                femdHoldingSnapshot($snapshot, $failingHolding, ['unrealized_gain_rate' => 5.0]);

                // 更新前の状態を明確に区別できる、あり得ない値(1.11)を
                // stale値として先に保存しておく。TechnicalIndicator::
                // updateOrCreate()自体は例外発生前に実行されるため、
                // トランザクションで包んでいなければこの値は新しい
                // 計算結果に更新されてしまう。
                $staleComputedAt = now()->subDays(30);
                TechnicalIndicator::create([
                    'holding_id' => $failingHolding->id,
                    'rsi' => 1.11,
                    'macd' => null,
                    'macd_signal' => null,
                    'ma20' => null,
                    'ma75' => null,
                    'bb_upper' => null,
                    'bb_lower' => null,
                    'computed_at' => $staleComputedAt,
                ]);

                $failingPriceHistory = femdPriceHistory(femdCloses(2000.0, 5.0, 80));

                $action = femdAction(
                    new FakeJpStockPriceClient(['7777' => $failingPriceHistory]),
                    new FakeUsStockPriceClient,
                    new FakeMarketIndexClient([
                        'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 30), 1_000_000, '2023-01-02'),
                        'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 30), 1_000_000, '2023-01-02'),
                    ]),
                    new FakeJQuantsClient(throwsForStatements: ['7777']),
                );

                $action->execute($batch);

                $row = TechnicalIndicator::where('holding_id', $failingHolding->id)->first();
                expect((float) $row->rsi)->toEqualWithDelta(1.11, 0.001);
                // DB column truncates sub-second precision, so compare at
                // second resolution rather than exact Carbon equality.
                expect($row->computed_at->format('Y-m-d H:i:s'))->toBe($staleComputedAt->format('Y-m-d H:i:s'));
            });
        });
    });

    describe('ADR-0009: 米国株ファンダメンタルズ指標（Finnhub統合）', function () {
        /*
        |----------------------------------------------------------------
        | Source of truth: docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md
        | (D1〜D5). Complements the rewritten "正常系（US株）" test above
        | (which pins the happy-path field mapping) with the per-holding
        | exception-isolation guarantee that every other external-data
        | source in this Action already has (see
        | describe('個別銘柄の失敗が全体を止めない') and
        | describe('ADR-0004 再発防止: ...') above for the equivalent JP/
        | J-Quants and price-history cases).
        |
        | Expected Red state: same as the rewritten US test above — fails
        | earlier at `app(FetchExternalMarketDataAction::class)` (class
        | doesn't exist yet) and, once that exists, at `new FakeFinnhubClient`
        | (FinnhubClientInterface doesn't exist yet either).
        |----------------------------------------------------------------
        */
        test('US個別株でFinnhubのfetchMetrics()が例外を投げても、その銘柄はスキップされ他の銘柄の処理は継続する', function () {
            [$batch, $snapshot] = femdImportBatch();

            $failingHolding = femdHolding([
                'symbol_code' => 'FAIL',
                'market' => 'us',
                'instrument_type' => 'stock',
                'symbol_name' => 'Finnhub取得失敗銘柄',
            ]);
            femdHoldingSnapshot($snapshot, $failingHolding, [
                'current_price' => 10000.0,
                'fx_rate_used' => 150.0,
                'unrealized_gain_rate' => 5.0,
            ]);

            $okHolding = femdHolding([
                'symbol_code' => 'AAPL',
                'market' => 'us',
                'instrument_type' => 'stock',
                'symbol_name' => 'Apple Inc.',
            ]);
            femdHoldingSnapshot($snapshot, $okHolding, [
                'current_price' => 25000.0,
                'fx_rate_used' => 150.0,
                'unrealized_gain_rate' => 8.0,
            ]);

            $failingPriceHistory = femdPriceHistory(femdCloses(100.0, 1.0, 20));
            $okPriceHistory = femdPriceHistory(femdCloses(150.0, 2.0, 20));

            $metrics = ['peTTM' => 37.3169, 'pbAnnual' => 50.978];
            $reportedFinancials = [
                ['operating_income' => 100000000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
                ['operating_income' => 80000000000.0, 'total_assets' => 352755000000.0, 'total_equity' => 56950000000.0],
            ];

            $action = femdAction(
                new FakeJpStockPriceClient,
                new FakeUsStockPriceClient(['FAIL' => $failingPriceHistory, 'AAPL' => $okPriceHistory]),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient,
                new FakeFinnhubClient(
                    metricsResponses: ['AAPL' => $metrics],
                    reportedFinancialsResponses: ['AAPL' => $reportedFinancials],
                    throwsForMetrics: ['FAIL'],
                ),
            );

            $action->execute($batch);

            // 失敗した銘柄はtechnical_indicatorsも含めて丸ごとロールバックされる
            // （1銘柄単位のアトミック性、既存のDB::transaction()挙動を踏襲）。
            $this->assertDatabaseMissing('technical_indicators', ['holding_id' => $failingHolding->id]);
            $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $failingHolding->id]);

            // 正常銘柄はFinnhub統合の影響を受けず処理が継続する。
            $this->assertDatabaseHas('technical_indicators', ['holding_id' => $okHolding->id]);
            $this->assertDatabaseHas('fundamental_indicators', ['holding_id' => $okHolding->id]);
        });

        test('US個別株でFinnhubのfetchReportedFinancials()が例外を投げても、その銘柄はスキップされ他の銘柄の処理は継続する', function () {
            [$batch, $snapshot] = femdImportBatch();

            $failingHolding = femdHolding([
                'symbol_code' => 'FAIL2',
                'market' => 'us',
                'instrument_type' => 'stock',
                'symbol_name' => 'Finnhub財務データ取得失敗銘柄',
            ]);
            femdHoldingSnapshot($snapshot, $failingHolding, [
                'current_price' => 10000.0,
                'fx_rate_used' => 150.0,
                'unrealized_gain_rate' => 5.0,
            ]);

            $okHolding = femdHolding([
                'symbol_code' => 'AAPL',
                'market' => 'us',
                'instrument_type' => 'stock',
                'symbol_name' => 'Apple Inc.',
            ]);
            femdHoldingSnapshot($snapshot, $okHolding, [
                'current_price' => 25000.0,
                'fx_rate_used' => 150.0,
                'unrealized_gain_rate' => 8.0,
            ]);

            $failingPriceHistory = femdPriceHistory(femdCloses(100.0, 1.0, 20));
            $okPriceHistory = femdPriceHistory(femdCloses(150.0, 2.0, 20));

            $metrics = ['peTTM' => 37.3169, 'pbAnnual' => 50.978];
            $reportedFinancials = [
                ['operating_income' => 100000000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
                ['operating_income' => 80000000000.0, 'total_assets' => 352755000000.0, 'total_equity' => 56950000000.0],
            ];

            $action = femdAction(
                new FakeJpStockPriceClient,
                new FakeUsStockPriceClient(['FAIL2' => $failingPriceHistory, 'AAPL' => $okPriceHistory]),
                new FakeMarketIndexClient([
                    'nikkei225' => femdPriceHistory(femdCloses(30000.0, 100.0, 20)),
                    'sp500' => femdPriceHistory(femdCloses(4500.0, 20.0, 20)),
                ]),
                new FakeJQuantsClient,
                new FakeFinnhubClient(
                    metricsResponses: ['FAIL2' => ['peTTM' => 10.0], 'AAPL' => $metrics],
                    reportedFinancialsResponses: ['AAPL' => $reportedFinancials],
                    throwsForReportedFinancials: ['FAIL2'],
                ),
            );

            $action->execute($batch);

            $this->assertDatabaseMissing('technical_indicators', ['holding_id' => $failingHolding->id]);
            $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $failingHolding->id]);

            $this->assertDatabaseHas('technical_indicators', ['holding_id' => $okHolding->id]);
            $this->assertDatabaseHas('fundamental_indicators', ['holding_id' => $okHolding->id]);
        });
    });
});
