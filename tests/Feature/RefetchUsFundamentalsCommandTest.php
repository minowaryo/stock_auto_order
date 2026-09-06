<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Services\Analysis\UsFundamentalIndicatorMapper;
use App\Services\MarketData\FinnhubClientInterface;
use Tests\Support\Fakes\FakeFinnhubClient;

/*
|--------------------------------------------------------------------------
| CHG-0011 / ADR-0009: 米国株ファンダメンタルズ再取得コマンド — Red phase
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md
|   - docs/rcid/traceability-matrix.md (CHG-0011)
|   - ~/.claude/plans/stock_auto_order-signal-market-value-and-us-fundamentals-implementation-phase.md
|
| ADR-0009 で FinnhubClient / UsFundamentalIndicatorMapper /
| FetchExternalMarketDataAction の US 分岐は実装済みだが、その分岐は CSV 取込
| （ImportCsvAction）時にしか走らない。既存の最新スナップショットに含まれる
| 米国株の fundamental_indicators を、CSV 取込を伴わずに補完するための軽量
| コマンド `market-data:refetch-us-fundamentals` を新設する。
|
| App\Console\Commands\RefetchUsFundamentalsCommand はまだ存在しないため、
| $this->artisan('market-data:refetch-us-fundamentals') は
| CommandNotFoundException で失敗する。これが意図した Red 状態。
|
| FetchExternalMarketDataAction 本体は変更しない（スコープ外）。US 分岐の
| ロジック（fetchMetrics + fetchReportedFinancials →
| UsFundamentalIndicatorMapper::map → FundamentalIndicator::updateOrCreate）
| は同コマンドに再掲する（一部重複を許容。canonical は
| FetchExternalMarketDataAction）。
*/

function refetchUsImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function refetchUsHolding(array $attributes = []): Holding
{
    return Holding::create(array_merge([
        'symbol_code' => 'AAPL',
        'market' => 'us',
        'instrument_type' => 'stock',
        'symbol_name' => 'Apple Inc.',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function refetchUsHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 20000.00,
        'current_price' => 25000.00,
        'fx_rate_used' => 150.0,
        'unrealized_gain_amount' => 50000.00,
        'unrealized_gain_rate' => 25.0,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * AAPL 相当の Finnhub レスポンス（FetchExternalMarketDataActionTest と同型）。
 *
 * @return array{0: array<string, mixed>, 1: array<int, array{operating_income: float|null, total_assets: float|null, total_equity: float|null}>}
 */
function refetchUsAaplFinnhubResponse(): array
{
    $metrics = [
        'peTTM' => 37.3169,
        'pbAnnual' => 50.978,
        'roeTTM' => 149.81,
        'revenueGrowthTTMYoy' => 5.97,
        'epsGrowthTTMYoy' => 10.2,
        'dividendYieldIndicatedAnnual' => 0.44,
        'payoutRatioTTM' => 15.5,
        'pegTTM' => 3.1,
    ];

    $reportedFinancials = [
        ['operating_income' => 100000000000.0, 'total_assets' => 359241000000.0, 'total_equity' => 73733000000.0],
        ['operating_income' => 80000000000.0, 'total_assets' => 352755000000.0, 'total_equity' => 56950000000.0],
    ];

    return [$metrics, $reportedFinancials];
}

describe('market-data:refetch-us-fundamentals', function () {
    test('最新スナップショットの米国株について Finnhub 由来のファンダメンタルズ指標が fundamental_indicators に保存される', function () {
        [, $snapshot] = refetchUsImportBatch();
        $holding = refetchUsHolding(['symbol_code' => 'AAPL']);
        refetchUsHoldingSnapshot($snapshot, $holding);

        [$metrics, $reportedFinancials] = refetchUsAaplFinnhubResponse();
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(
            metricsResponses: ['AAPL' => $metrics],
            reportedFinancialsResponses: ['AAPL' => $reportedFinancials],
        ));

        $this->artisan('market-data:refetch-us-fundamentals')->assertSuccessful();

        $expected = (new UsFundamentalIndicatorMapper)->map($metrics, $reportedFinancials);

        $indicator = FundamentalIndicator::where('holding_id', $holding->id)->first();
        expect($indicator)->not->toBeNull();
        expect((float) $indicator->per)->toEqualWithDelta((float) $expected['per'], 0.01);
        expect((float) $indicator->roe)->toEqualWithDelta((float) $expected['roe'], 0.01);
        expect((float) $indicator->equity_ratio)->toEqualWithDelta((float) $expected['equity_ratio'], 0.01);
        expect($indicator->fetched_at)->not->toBeNull();
    });

    test('日本株・ETF・投資信託は対象外で fundamental_indicators に触れない', function () {
        [, $snapshot] = refetchUsImportBatch();

        $jpHolding = refetchUsHolding(['symbol_code' => '7203', 'market' => 'jp', 'instrument_type' => 'stock', 'symbol_name' => 'トヨタ自動車']);
        refetchUsHoldingSnapshot($snapshot, $jpHolding);

        $usEtf = refetchUsHolding(['symbol_code' => 'VOO', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => 'Vanguard S&P 500 ETF']);
        refetchUsHoldingSnapshot($snapshot, $usEtf);

        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

        $this->artisan('market-data:refetch-us-fundamentals')->assertSuccessful();

        $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $jpHolding->id]);
        $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $usEtf->id]);
    });

    test('Finnhub 取得に失敗した米国株はスキップされ、コマンドは成功終了し他の米国株は処理される', function () {
        [, $snapshot] = refetchUsImportBatch();

        $failing = refetchUsHolding(['symbol_code' => 'FAIL', 'symbol_name' => '取得失敗銘柄']);
        refetchUsHoldingSnapshot($snapshot, $failing);

        $ok = refetchUsHolding(['symbol_code' => 'AAPL', 'symbol_name' => 'Apple Inc.']);
        refetchUsHoldingSnapshot($snapshot, $ok);

        [$metrics, $reportedFinancials] = refetchUsAaplFinnhubResponse();
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(
            metricsResponses: ['AAPL' => $metrics],
            reportedFinancialsResponses: ['AAPL' => $reportedFinancials],
            throwsForMetrics: ['FAIL'],
        ));

        $this->artisan('market-data:refetch-us-fundamentals')->assertSuccessful();

        $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $failing->id]);
        $this->assertDatabaseHas('fundamental_indicators', ['holding_id' => $ok->id]);
    });

    test('スナップショットが1件も存在しない場合は失敗ステータスを返す', function () {
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

        $this->artisan('market-data:refetch-us-fundamentals')->assertFailed();
    });

    test('最新スナップショットに含まれない米国株（過去スナップショットのみ）は対象外', function () {
        [, $oldSnapshot] = refetchUsImportBatch(now()->subWeek());
        [, $latestSnapshot] = refetchUsImportBatch(now());

        $oldOnly = refetchUsHolding(['symbol_code' => 'OLD', 'symbol_name' => '旧保有銘柄']);
        refetchUsHoldingSnapshot($oldSnapshot, $oldOnly);

        $current = refetchUsHolding(['symbol_code' => 'AAPL', 'symbol_name' => 'Apple Inc.']);
        refetchUsHoldingSnapshot($latestSnapshot, $current);

        [$metrics, $reportedFinancials] = refetchUsAaplFinnhubResponse();
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient(
            metricsResponses: ['AAPL' => $metrics, 'OLD' => $metrics],
            reportedFinancialsResponses: ['AAPL' => $reportedFinancials, 'OLD' => $reportedFinancials],
        ));

        $this->artisan('market-data:refetch-us-fundamentals')->assertSuccessful();

        $this->assertDatabaseHas('fundamental_indicators', ['holding_id' => $current->id]);
        $this->assertDatabaseMissing('fundamental_indicators', ['holding_id' => $oldOnly->id]);
    });
});
