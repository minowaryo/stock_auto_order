<?php

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Livewire\Candidate\CandidateCheck;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use App\Models\WatchlistBuySignal;
use App\Models\WatchlistItem;
use App\Services\Analysis\CriteriaValuationMetricsBuilder;
use App\Services\Analysis\SignalCriteriaEvaluator;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Livewire\Livewire;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| CHG-0048 サイクル4: 買い増し判定のPER基準の業種相対化 — 呼び出し元・チップ・画面（Red phase）
|--------------------------------------------------------------------------
|
| Source of truth: docs/adr/ADR-0026 D2・D3（2026-10-04改訂）,
| docs/product/use-cases.md UC-010（PER基準の業種相対化）・UC-012,
| docs/product/valuation-benchmarks.md 3・4章, config/valuation_benchmarks.php（実設定の値）。
|
| C. 呼び出し元（FetchExternalMarketDataAction＝保有の買い増し判定、
|    RefreshWatchlistMarketDataAction＝ウォッチリストの一括更新）が holding の market と
|    sectorClassification の name を BuySignalDeterminationService::determine() に渡す。
|    業種は J-Quants の業種情報（17業種名）から解決される経路で与える。
| D. SignalCriteriaEvaluator::evaluateBuy() の $metrics['buy_per_verdict']
|    （ValuationBenchmarkJudge::buyPerVerdict() の配列）で PERチップの基準表示と達成状態が決まる。
|    basis sector: 「≤{threshold}（業種基準{benchmark}の{0.8|0.7}倍）」／ basis fixed: 「≤15.0」。
|    met → 'met'、met でなく PER≦threshold×1.2 → 'near'、それ以外 'unmet'、
|    PER null → 'unavailable'、PER≦0 → 'unmet'。キーが無ければ従来どおり。
|    PERチップには 'valuation' キーを付けない。
|    CriteriaValuationMetricsBuilder が 'buy_per_verdict' を組み立て、買い増し候補と
|    ウォッチリストの criteria に反映される。
| E. /candidate-check（Livewire CandidateCheck）の描画で PERチップに業種基準の表示、
|    PBRチップに業種比較のラベル（chip-valuation）が出る。
|
| 基準値（実設定）: 小売 PER 22.5 high（0.8倍 = 18.0）、PBR 1.90 high。
|
| Expected Red:
|   C: PER17.4 は従来の固定15を超えるため per_undervalued が保存されない（市場・業種が渡っていない）。
|   D: buy_per_verdict が無視され threshold_label が「≤15.0」のまま／Builder に buy_per_verdict キーが無い。
|   E: 画面に「業種基準22.5の0.8倍」が出ない。
|
| 関数名は他ファイルとの再宣言衝突を避けるため `chg48` 接頭辞。
*/

// ---------------------------------------------------------------------
// 共通フィクスチャ
// ---------------------------------------------------------------------

/**
 * @param  array<int, float>  $closes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function chg48Price(array $closes, string $startDate = '2024-01-07', int $volume = 100000): array
{
    $rows = [];
    $date = new DateTimeImmutable($startDate);

    foreach (array_values($closes) as $i => $close) {
        $rows[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => $volume,
        ];
    }

    return $rows;
}

/**
 * @return array<int, float> start から +5/週で weeks 週分（なだらかな上昇。最終週が52週高値）
 */
function chg48Rising(float $start, int $weeks = 60): array
{
    return array_map(fn (int $i) => $start + $i * 5, range(0, $weeks - 1));
}

/**
 * 最新FYのEPS100（PER = 現在値 / 100）。売上・営業利益は+20%／+25%（低成長ではない）、
 * 自己資本比率55%・ROE12.5%・営業利益率12.5%。
 *
 * @return array<int, array<string, mixed>>
 */
function chg48Statements(): array
{
    $latest = [
        'net_sales' => 120000.0,
        'operating_profit' => 15000.0,
        'profit' => 10000.0,
        'eps' => 100.0,
        'book_value_per_share' => 800.0,
        'equity_to_asset_ratio' => 0.55,
        'roe' => 0.125,
        'dividend_per_share_annual' => 30.0,
        'payout_ratio_annual' => 0.30,
    ];

    return [
        array_merge($latest, ['disclosed_date' => '2026-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2026-03-31']),
        array_merge($latest, [
            'disclosed_date' => '2025-05-15', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31',
            'net_sales' => 100000.0, 'operating_profit' => 12000.0, 'eps' => 80.0,
        ]),
    ];
}

function chg48Sector(string $market, string $name): int
{
    return SectorClassification::firstOrCreate(['market' => $market, 'name' => $name], ['code' => null])->id;
}

/**
 * 判定チェックリストの行（ShowBuySignalListAction / ShowWatchlistAction）用の指標。
 *
 * @param  array<string, mixed>  $fundamental
 */
function chg48Indicators(Holding $holding, array $fundamental): void
{
    TechnicalIndicator::create([
        'holding_id' => $holding->id,
        'rsi' => 50.0, 'macd' => 1.0, 'macd_signal' => 0.5,
        'ma20' => 1000.0, 'ma75' => 950.0, 'bb_upper' => 1100.0, 'bb_lower' => 900.0,
        'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0,
        'week52_high' => 1200.0, 'week52_low' => 800.0,
        'relative_strength_vs_market' => 6.0, 'relative_strength_vs_sector' => 6.0,
        'computed_at' => now(),
    ]);

    FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => null, 'pbr' => null, 'roe' => 12.0, 'revenue_growth' => 5.0,
        'operating_income_growth' => 5.0, 'equity_ratio' => 45.0, 'operating_margin' => 12.0,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ], $fundamental));
}

/**
 * 買い増し候補（UC-010）に出る保有銘柄（買い増しシグナル1件・利確シグナル0件）。
 *
 * @param  array<string, mixed>  $fundamental
 */
function chg48BuyHolding(string $market, ?string $sector, array $fundamental): Holding
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp_stock.csv', 'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null, 'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);

    $holding = Holding::create([
        'symbol_code' => '9999', 'market' => $market, 'instrument_type' => 'stock', 'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => $sector === null ? null : chg48Sector($market, $sector),
        'first_detected_at' => now(),
    ]);

    $holdingSnapshot = HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id, 'quantity' => 100, 'average_cost' => 1000,
        'current_price' => 1050, 'fx_rate_used' => null, 'unrealized_gain_amount' => 5000,
        'unrealized_gain_rate' => 5.0, 'ma20' => null, 'ma75' => null, 'is_newly_detected' => false,
    ]);

    chg48Indicators($holding, $fundamental);

    BuySignal::create([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => 'RSIが28から34へ反発しました',
    ]);

    return $holding;
}

/**
 * 未保有のウォッチリスト銘柄（UC-012 の一覧に出る）。
 *
 * @param  array<string, mixed>  $fundamental
 */
function chg48WatchlistHolding(string $market, ?string $sector, array $fundamental): WatchlistItem
{
    $holding = Holding::create([
        'symbol_code' => '9999', 'market' => $market, 'instrument_type' => 'stock', 'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => $sector === null ? null : chg48Sector($market, $sector),
        'first_detected_at' => now(),
    ]);

    chg48Indicators($holding, $fundamental);

    return WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => 'テーマA',
        'exchange_label' => '東Ｐ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => false,
        'last_close' => 1740.0,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);
}

/** @param array<string, mixed> $row */
function chg48Chip(array $row, string $label): array
{
    $chip = collect($row['criteria']['technical'])->firstWhere('label', $label);
    expect($chip)->not->toBeNull("チップ {$label} が見つかりません");

    return $chip;
}

/** @param array<int, array<string, mixed>> $rows */
function chg48Row(array $rows): array
{
    $row = collect($rows)->firstWhere('symbol_code', '9999');
    expect($row)->not->toBeNull('銘柄 9999 の行が出力されていません');

    return $row;
}

/**
 * @return array{met: bool, basis: string, threshold: float, benchmark: ?float, factor: ?float}
 */
function chg48Verdict(bool $met, string $basis, float $threshold, ?float $benchmark = null, ?float $factor = null): array
{
    return ['met' => $met, 'basis' => $basis, 'threshold' => $threshold, 'benchmark' => $benchmark, 'factor' => $factor];
}

/** @return array<string, mixed> evaluateBuy 用の最小の指標 */
function chg48BuyMetrics(?float $per, ?array $verdict): array
{
    $metrics = [
        'rsi' => 50.0, 'current_price' => 1000.0, 'week52_low' => 800.0, 'bb_lower' => 900.0,
        'macd' => 1.0, 'macd_signal' => 0.5, 'ma20' => 1000.0, 'peg_ratio' => 1.2,
        'volume' => 1000.0, 'volume_ma20' => 1000.0, 'per' => $per, 'pbr' => 1.0,
        'roe' => 12.0, 'equity_ratio' => 45.0, 'operating_margin' => 12.0,
        'revenue_growth' => 5.0, 'operating_income_growth' => 5.0,
    ];

    if ($verdict !== null) {
        $metrics['buy_per_verdict'] = $verdict;
    }

    return $metrics;
}

function chg48EvaluatorPerChip(?float $per, ?array $verdict): array
{
    $criteria = app(SignalCriteriaEvaluator::class)->evaluateBuy(chg48BuyMetrics($per, $verdict));

    return chg48Chip(['criteria' => $criteria], 'PER');
}

// ---------------------------------------------------------------------
// C. 呼び出し元から市場・業種を渡す
// ---------------------------------------------------------------------

describe('C: 呼び出し元が市場・業種を買い増し判定に渡す', function () {
    test('UC-010: 保有の買い増し判定（FetchExternalMarketDataAction）で、日本・小売のPER17.4の保有に per_undervalued の買いシグナルが保存される', function () {
        // Arrange: 現在値1740・EPS100 → PER17.4（小売の基準22.5に対し比率0.77）。
        // 価格推移は range(100,151)（前提条件A・Bを満たし、テクニカル条件は単独で成立しない）。
        $batch = ImportBatch::create([
            'status' => 'completed', 'jp_stock_filename' => 'jp_stock.csv', 'us_stock_filename' => 'us_stock.csv',
            'mutual_fund_filename' => null, 'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
        ]);
        $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
        $holding = Holding::create([
            'symbol_code' => '3088', 'market' => 'jp', 'instrument_type' => 'stock', 'symbol_name' => '小売テスト',
            'sector_classification_id' => null, 'first_detected_at' => now(),
        ]);
        $holdingSnapshot = HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id, 'quantity' => 100, 'average_cost' => 1500,
            'current_price' => 1740, 'fx_rate_used' => null, 'unrealized_gain_amount' => 24000,
            'unrealized_gain_rate' => 16.0, 'ma20' => null, 'ma75' => null, 'is_newly_detected' => false,
        ]);

        app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['3088' => chg48Price(range(100, 151), '2024-01-01', 1000)]));
        app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
        app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient([
            'nikkei225' => chg48Price(array_map(fn (int $i) => 30000.0 - 807.6923076923077 * $i, range(0, 13)), '2023-01-02', 1_000_000),
            'sp500' => chg48Price(array_map(fn (int $i) => 4500.0 + 20.0 * $i, range(0, 13)), '2023-01-02', 1_000_000),
        ]));
        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
            sectorResponses: ['3088' => ['code' => '14', 'name' => '小売']],
            statementsResponses: ['3088' => chg48Statements()],
        ));

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert (fixture sanity): 業種は小売、保存されたPERは17.4
        expect($holding->fresh()->sectorClassification?->name)->toBe('小売');
        expect((float) FundamentalIndicator::where('holding_id', $holding->id)->value('per'))->toEqualWithDelta(17.4, 0.0001);

        // Assert
        $types = BuySignal::where('holding_snapshot_id', $holdingSnapshot->id)->pluck('signal_type')->all();
        expect($types)->toContain('per_undervalued');
    });

    test('UC-012: ウォッチリストの一括更新（RefreshWatchlistMarketDataAction）で、日本・小売のPER17.4の銘柄に per_undervalued の買いシグナルが保存される', function () {
        // Arrange: 60週かけて1445→1740へ+5/週（最終週の終値1740＝現在値）。EPS100 → PER17.4。
        $holding = Holding::create([
            'symbol_code' => '3088', 'market' => 'jp', 'instrument_type' => 'stock', 'symbol_name' => '小売テスト',
            'first_detected_at' => now(),
        ]);
        WatchlistItem::create([
            'holding_id' => $holding->id, 'folder_name' => 'テーマ', 'exchange_label' => '東Ｐ',
            'source' => 'rakuten_favorites_csv', 'last_seen_in_csv_at' => now(), 'registered_at' => now(),
        ]);

        app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient([
            'nikkei225' => chg48Price(chg48Rising(30000.0)),
            'sp500' => chg48Price(chg48Rising(5000.0)),
        ]));
        app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['3088' => chg48Price(chg48Rising(1445.0))]));
        app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(
            ['3088' => ['code' => '14', 'name' => '小売']],
            ['3088' => chg48Statements()],
        ));
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

        // Act
        app(RefreshWatchlistMarketDataAction::class)->execute();

        // Assert (fixture sanity)
        expect($holding->fresh()->sectorClassification?->name)->toBe('小売');
        expect((float) FundamentalIndicator::where('holding_id', $holding->id)->value('per'))->toEqualWithDelta(17.4, 0.0001);

        // Assert
        $types = WatchlistBuySignal::where('holding_id', $holding->id)->pluck('signal_type')->all();
        expect($types)->toContain('per_undervalued');
    });
});

// ---------------------------------------------------------------------
// D. 判定チェックリストのPERチップ
// ---------------------------------------------------------------------

describe('D: SignalCriteriaEvaluator::evaluateBuy の buy_per_verdict', function () {
    test('UC-010: 業種基準（倍率0.8）で成立のPERチップは「≤16.0（業種基準20.0の0.8倍）」で met', function () {
        // Act
        $chip = chg48EvaluatorPerChip(15.8, chg48Verdict(true, 'sector', 16.0, 20.0, 0.8));

        // Assert
        expect($chip['threshold_label'])->toBe('≤16.0（業種基準20.0の0.8倍）');
        expect($chip['status'])->toBe('met');
    });

    test('UC-010: 業種基準（倍率0.7、信頼度low）のPERチップは「≤14.0（業種基準20.0の0.7倍）」と表示する', function () {
        // Act
        $chip = chg48EvaluatorPerChip(13.8, chg48Verdict(true, 'sector', 14.0, 20.0, 0.7));

        // Assert
        expect($chip['threshold_label'])->toBe('≤14.0（業種基準20.0の0.7倍）');
        expect($chip['status'])->toBe('met');
    });

    test('UC-010: 業種基準で不成立でも PER≦閾値×1.2 なら near、それを超えると unmet', function () {
        // Arrange: 閾値16.0 → nearの上限 19.2
        // Act
        $boundary = chg48EvaluatorPerChip(16.0, chg48Verdict(false, 'sector', 16.0, 20.0, 0.8)); // 比率0.80ちょうど＝不成立
        $near = chg48EvaluatorPerChip(19.0, chg48Verdict(false, 'sector', 16.0, 20.0, 0.8));
        $unmet = chg48EvaluatorPerChip(19.5, chg48Verdict(false, 'sector', 16.0, 20.0, 0.8));

        // Assert
        expect($boundary['status'])->toBe('near');
        expect($near['status'])->toBe('near');
        expect($unmet['status'])->toBe('unmet');
    });

    test('UC-010: 固定基準（業種なし）のPERチップは従来どおり「≤15.0」で、met/near/unmet も15基準', function () {
        // Act
        $met = chg48EvaluatorPerChip(15.0, chg48Verdict(true, 'fixed', 15.0));
        $near = chg48EvaluatorPerChip(17.0, chg48Verdict(false, 'fixed', 15.0));
        $unmet = chg48EvaluatorPerChip(18.5, chg48Verdict(false, 'fixed', 15.0));

        // Assert
        expect($met['threshold_label'])->toBe('≤15.0');
        expect($met['status'])->toBe('met');
        expect($near['status'])->toBe('near');
        expect($unmet['status'])->toBe('unmet');
    });

    test('UC-010: PERがnullなら unavailable、0以下なら unmet（業種基準でも met/near にしない）', function () {
        // Act
        $null = chg48EvaluatorPerChip(null, chg48Verdict(false, 'sector', 16.0, 20.0, 0.8));
        $negative = chg48EvaluatorPerChip(-8.0, chg48Verdict(false, 'sector', 16.0, 20.0, 0.8));
        $zero = chg48EvaluatorPerChip(0.0, chg48Verdict(false, 'sector', 16.0, 20.0, 0.8));

        // Assert
        expect($null['status'])->toBe('unavailable');
        expect($null['value_label'])->toBe('—');
        expect($negative['status'])->toBe('unmet');
        expect($zero['status'])->toBe('unmet');
    });

    test('UC-010: buy_per_verdict を渡してもPERチップには valuation キーを付けない', function () {
        // Act
        $chip = chg48EvaluatorPerChip(15.8, chg48Verdict(true, 'sector', 16.0, 20.0, 0.8));

        // Assert
        expect(array_key_exists('valuation', $chip))->toBeFalse();
    });

    test('回帰: buy_per_verdict が無いときPERチップは従来どおり「≤15.0」・PER17.4は near', function () {
        // Act
        $chip = chg48EvaluatorPerChip(17.4, null);

        // Assert
        expect($chip['threshold_label'])->toBe('≤15.0');
        expect($chip['status'])->toBe('near');
    });

    test('UC-010: CriteriaValuationMetricsBuilder は日本・小売PER17.4で buy_per_verdict（業種基準22.5・0.8倍・閾値18.0・成立）を組み立てる', function () {
        // Arrange
        $indicator = (new FundamentalIndicator)->forceFill(['per' => 17.4, 'pbr' => 1.5]);

        // Act
        $built = app(CriteriaValuationMetricsBuilder::class)->build('jp', '小売', $indicator);

        // Assert
        expect($built)->toHaveKey('buy_per_verdict');
        $verdict = $built['buy_per_verdict'];
        expect($verdict['met'])->toBeTrue();
        expect($verdict['basis'])->toBe('sector');
        expect($verdict['threshold'])->toBe(18.0);
        expect($verdict['benchmark'])->toBe(22.5);
        expect($verdict['factor'])->toBe(0.8);
    });

    test('UC-010: 買い増し候補（ShowBuySignalListAction）で日本・小売PER17.4のPERチップは met で「業種基準22.5の0.8倍」を表示する', function () {
        // Arrange
        chg48BuyHolding('jp', '小売', ['per' => 17.4, 'pbr' => 1.5]);

        // Act
        $chip = chg48Chip(chg48Row(app(ShowBuySignalListAction::class)->execute()), 'PER');

        // Assert
        expect($chip['status'])->toBe('met');
        expect($chip['threshold_label'])->toBe('≤18.0（業種基準22.5の0.8倍）');
        expect(array_key_exists('valuation', $chip))->toBeFalse();
    });

    test('UC-012: ウォッチリスト（ShowWatchlistAction）で日本・小売PER17.4のPERチップは met で「業種基準22.5の0.8倍」を表示する', function () {
        // Arrange
        chg48WatchlistHolding('jp', '小売', ['per' => 17.4, 'pbr' => 1.5]);

        // Act
        $chip = chg48Chip(chg48Row(app(ShowWatchlistAction::class)->execute()), 'PER');

        // Assert
        expect($chip['status'])->toBe('met');
        expect($chip['threshold_label'])->toBe('≤18.0（業種基準22.5の0.8倍）');
        expect(array_key_exists('valuation', $chip))->toBeFalse();
    });

    test('回帰: 買い増し候補で業種未分類のPER17.4は「≤15.0」の near のまま', function () {
        // Arrange
        chg48BuyHolding('jp', null, ['per' => 17.4, 'pbr' => 1.5]);

        // Act
        $chip = chg48Chip(chg48Row(app(ShowBuySignalListAction::class)->execute()), 'PER');

        // Assert
        expect($chip['threshold_label'])->toBe('≤15.0');
        expect($chip['status'])->toBe('near');
    });
});

// ---------------------------------------------------------------------
// E. 新規投資候補の画面（/candidate-check）の統合
// ---------------------------------------------------------------------

/** 画面HTMLから、指定ラベルのチップ（ラベルspanを含む<td>の中身）を返す。 */
function chg48ChipHtml(string $html, string $label): string
{
    expect(preg_match('/<td[^>]*>\s*<div[^>]*rounded border[^>]*>(?:(?!<\/td>).)*?<span[^>]*>\s*'.preg_quote($label, '/').'\s*<\/span>.*?<\/td>/su', $html, $m))->toBe(1, "チップ {$label} が見つかりません");

    return $m[0];
}

describe('E: 新規投資候補の画面（CandidateCheck）', function () {
    test('UC-012: 日本・小売PER17.4のウォッチリスト銘柄は、PERチップに「業種基準22.5の0.8倍」が出て、PBRチップに業種比較のラベルが出る', function () {
        // Arrange: PBR1.5 / 基準1.90 = 0.79 → 割安
        chg48WatchlistHolding('jp', '小売', ['per' => 17.4, 'pbr' => 1.5]);

        // Act
        $html = Livewire::actingAs(User::factory()->create())->test(CandidateCheck::class)->html();
        $per = chg48ChipHtml($html, 'PER');
        $pbr = chg48ChipHtml($html, 'PBR');

        // Assert（PBRの業種比較ラベルはサイクル3で実装済み。PERの業種基準の表示が今回の未実装分）
        expect($pbr)->toContain('chip-valuation')->toContain('割安');
        expect($per)->not->toContain('chip-valuation');
        expect($per)->toContain('業種基準22.5の0.8倍');
    });
});
