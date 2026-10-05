<?php

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Livewire\Holding\HoldingDetail;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\SignalOccurrence;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use App\Services\SignalOutcome\SignalOccurrenceMetricsBuilder;
use App\Support\SignalOccurrenceMetricLabels;
use Livewire\Livewire;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| CHG-0048 フォローアップ — Red phase
|--------------------------------------------------------------------------
|
| 根拠: ADR-0026 D3（2026-10-04追記: valuation_zone_badge のPER条件も業種相対）・D5
| （signal_occurrences.metrics に per_basis / per_benchmark / per_factor /
| per_valuation_tier を保存）, use-cases.md UC-004 `valuation_zone_badge`・UC-014,
| data-model.md `signal_occurrences.metrics`, 画面確認で見つかった見た目の不具合。
|
| 基準値は config/valuation_benchmarks.php の実設定（日本株は単純平均）:
|   建設・資材 PER 15.4（medium → 比率0.80未満）、小売 PER 22.5（high → 比率0.80未満）。
|
| 契約として仮定した点（Gate 4 で別の形が望ましければ指摘）:
|   - SignalOccurrenceMetricsBuilder::build() は名前付き引数 market: / sectorName:
|     （BuySignalDeterminationService と同じ引数名）で保有の市場・業種名を受け取る。
|   - 数値の表示は既存の format() の流儀（小数2桁）。per_factor だけは「0.8倍」。
|
| 関数名は他ファイルとの再宣言衝突を避けるため `chg48f` 接頭辞。
*/

// ---------------------------------------------------------------------------
// フィクスチャ
// ---------------------------------------------------------------------------

function chg48fSectorId(string $market, ?string $name): ?int
{
    return $name === null
        ? null
        : SectorClassification::firstOrCreate(['market' => $market, 'name' => $name], ['code' => null])->id;
}

function chg48fSnapshot(): Snapshot
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
 * 利確検討（UC-004）の一覧に載る保有（含み益+30%・シグナル1件・低成長の健全な財務）。
 *
 * @param  array<string, mixed>  $fundamental
 */
function chg48fTakeProfitHolding(string $code, ?string $sector, array $fundamental): Holding
{
    $snapshot = Snapshot::query()->latest('id')->first() ?? chg48fSnapshot();

    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code,
        'sector_classification_id' => chg48fSectorId('jp', $sector),
        'first_detected_at' => now(),
    ]);
    $holdingSnapshot = HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 300,
        'average_cost' => 1000,
        'current_price' => 1300,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 90000,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
    Signal::create([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ]);
    FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 15.2,
        // LowGrowthDeterminer: 売上高・営業利益の高い方が5%以下なら低成長
        'revenue_growth' => 4.0,
        'operating_income_growth' => 4.0,
        'equity_ratio' => 58.0,
        'operating_margin' => 18.3,
        'dividend_yield' => 3.5,
        'dividend_payout_ratio' => 30.0,
        'fetched_at' => now(),
    ], $fundamental));

    return $holding;
}

/** @return array<string, mixed>|null 利確検討一覧（GET /api/signals）の該当行 */
function chg48fSignalRow(string $code): ?array
{
    $rows = test()->actingAs(User::factory()->create())->getJson('/api/signals')->assertSuccessful()->json('data') ?? [];

    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $code) {
            return $row;
        }
    }

    return null;
}

/**
 * @param  array<int, float|int>  $closes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function chg48fWeekly(array $closes, int $volume = 100000): array
{
    $last = new DateTimeImmutable('2026-09-20');
    $count = count($closes);
    $rows = [];

    foreach (array_values($closes) as $i => $close) {
        $weeksBack = $count - 1 - $i;
        $rows[] = ['date' => $last->modify("-{$weeksBack} weeks")->format('Y-m-d'), 'close' => (float) $close, 'volume' => $volume];
    }

    return $rows;
}

function chg48fDom(string $html): DOMXPath
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
    libxml_clear_errors();

    return new DOMXPath($doc);
}

/** チップの基準の行（threshold_label を表示している要素）の class。見つからなければ null。 */
function chg48fThresholdClass(array $item): ?string
{
    $html = (string) test()->blade('<x-criteria-chip :item="$item" />', ['item' => $item]);

    foreach (chg48fDom($html)->query('//body/*[1]//*') as $node) {
        if (trim($node->textContent) === $item['threshold_label']) {
            return $node->getAttribute('class');
        }
    }

    return null;
}

/** @param  array<string, mixed>  $overrides */
function chg48fChipItem(array $overrides = []): array
{
    return array_merge([
        'label' => 'PER',
        'value_label' => '10.0倍',
        'threshold_label' => '≦18.0（業種基準22.5の0.8倍）',
        'status' => 'met',
    ], $overrides);
}

/** 銘柄詳細（UC-003）の画面HTML。業種は小売（PER/PBRとも判定あり）。 */
function chg48fDetailHtml(): string
{
    $snapshot = chg48fSnapshot();
    $holding = Holding::create([
        'symbol_code' => '3088',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト小売',
        'sector_classification_id' => chg48fSectorId('jp', '小売'),
        'first_detected_at' => now(),
    ]);
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 2000,
        'current_price' => 2500,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 5000,
        'unrealized_gain_rate' => 25.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
    FundamentalIndicator::create([
        'holding_id' => $holding->id,
        'per' => 17.4, 'pbr' => 1.3, 'roe' => 16.0, 'revenue_growth' => -12.0,
        'operating_income_growth' => null, 'equity_ratio' => 45.0, 'operating_margin' => 5.0,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => null, 'fetched_at' => now(),
    ]);

    return Livewire::actingAs(User::factory()->create())->test(HoldingDetail::class, ['holding' => $holding->fresh()])->html();
}

// ---------------------------------------------------------------------------
// 1. 利確検討の valuation_zone_badge を業種相対にそろえる（UC-004、ADR-0026 D3追記）
// ---------------------------------------------------------------------------

describe('UC-004 valuation_zone_badge のPER条件は買い増し判定と同じ業種相対の基準（ADR-0026 D3追記）', function () {
    test('建設・資材（基準15.4・medium）でPER13.3は基準比0.86のため、低成長・配当3.5%でも割安ゾーンの補助バッジは出ない', function () {
        // Arrange: 従来の固定PER≦15なら該当していた
        chg48fTakeProfitHolding('1417', '建設・資材', ['per' => 13.3, 'dividend_yield' => 3.5]);

        // Act
        $row = chg48fSignalRow('1417');

        // Assert
        expect($row)->not->toBeNull();
        expect($row)->toHaveKey('valuation_zone_badge');
        expect($row['valuation_zone_badge'])->toBeNull();
    });

    test('小売（基準22.5・high）でPER17.4は基準比0.77のため、低成長・配当3.5%なら割安ゾーンの補助バッジが出る', function () {
        // Arrange: 従来の固定PER≦15では該当しなかった
        chg48fTakeProfitHolding('3088', '小売', ['per' => 17.4, 'dividend_yield' => 3.5]);

        // Act
        $row = chg48fSignalRow('3088');

        // Assert
        expect($row)->not->toBeNull();
        expect($row['valuation_zone_badge'])->toBe('絶対バリュエーション上は割安ゾーン');
    });

    test('業種未分類の銘柄は従来どおり固定のPER≦15で判定する（PER14.0は補助バッジあり、PER16.0はなし）', function (float $per, ?string $expected) {
        // Arrange
        chg48fTakeProfitHolding('9001', null, ['per' => $per, 'dividend_yield' => 3.5]);

        // Act
        $row = chg48fSignalRow('9001');

        // Assert
        expect($row)->not->toBeNull();
        expect($row['valuation_zone_badge'])->toBe($expected);
    })->with([
        'PER14.0' => [14.0, '絶対バリュエーション上は割安ゾーン'],
        'PER16.0' => [16.0, null],
    ]);

    test('業種相対のPER条件を満たしても、配当利回り3%未満または高成長なら補助バッジは出ない', function (array $fundamental) {
        // Arrange: 小売 PER17.4（業種相対では割安）
        chg48fTakeProfitHolding('3089', '小売', array_merge(['per' => 17.4, 'dividend_yield' => 3.5], $fundamental));

        // Act
        $row = chg48fSignalRow('3089');

        // Assert
        expect($row)->not->toBeNull();
        expect($row['valuation_zone_badge'])->toBeNull();
    })->with([
        '配当利回り2.9%' => [['dividend_yield' => 2.9]],
        '高成長（売上成長率12%）' => [['revenue_growth' => 12.0, 'operating_income_growth' => 12.0]],
    ]);
});

// ---------------------------------------------------------------------------
// 2. シグナル発生記録に判定の基準を残す（UC-014、ADR-0026 D5）
// ---------------------------------------------------------------------------

describe('UC-014 シグナル発生記録の判定根拠値にPER判定の基準を保存する（ADR-0026 D5）', function () {
    /** @return list<string> 変更前から保存していたキー（回帰ガード） */
    $existingKeys = fn (): array => [
        'close', 'rsi', 'week52_high', 'relative_strength_vs_market', 'relative_strength_vs_sector', 'ma75_trend_rising',
        'per', 'pbr', 'peg_ratio', 'roe', 'equity_ratio', 'operating_margin',
        'revenue_growth', 'operating_income_growth', 'avg_revenue_growth', 'avg_operating_income_growth', 'dividend_yield',
    ];

    test('業種のある保有（日本・小売、PER17.4）は、業種比較の基準・基準PER・倍率・段階が保存され、既存のキーは変わらない', function () use ($existingKeys) {
        // Arrange
        $builder = app(SignalOccurrenceMetricsBuilder::class);
        $priceHistory = [['date' => '2026-09-20', 'close' => 1300.0, 'volume' => 1000]];

        // Act
        $metrics = $builder->build($priceHistory, ['rsi' => 55.0], ['per' => 17.4, 'dividend_yield' => 3.5], market: 'jp', sectorName: '小売');

        // Assert
        expect($metrics)->toHaveKeys([...$existingKeys(), 'per_basis', 'per_benchmark', 'per_factor', 'per_valuation_tier']);
        expect($metrics['per_basis'])->toBe('sector');
        expect((float) $metrics['per_benchmark'])->toBe(22.5);
        expect((float) $metrics['per_factor'])->toBe(0.8);
        expect($metrics['per_valuation_tier'])->toBe('cheap'); // 17.4 / 22.5 = 0.77
        // 既存のキーの値は変わらない
        expect((float) $metrics['close'])->toBe(1300.0);
        expect((float) $metrics['per'])->toBe(17.4);
        expect((float) $metrics['dividend_yield'])->toBe(3.5);
        expect((float) $metrics['rsi'])->toBe(55.0);
    });

    test('業種未分類の保有は、固定の基準として保存し、基準PER・倍率・段階はnullになる', function () use ($existingKeys) {
        // Arrange
        $builder = app(SignalOccurrenceMetricsBuilder::class);

        // Act
        $metrics = $builder->build([['date' => '2026-09-20', 'close' => 1300.0, 'volume' => 1000]], [], ['per' => 14.0], market: 'jp', sectorName: null);

        // Assert
        expect($metrics)->toHaveKeys([...$existingKeys(), 'per_basis', 'per_benchmark', 'per_factor', 'per_valuation_tier']);
        expect($metrics['per_basis'])->toBe('fixed');
        expect($metrics['per_benchmark'])->toBeNull();
        expect($metrics['per_factor'])->toBeNull();
        expect($metrics['per_valuation_tier'])->toBeNull();
    });

    test('CSV取込の分析処理で、業種のある保有に記録されたシグナル発生記録の判定根拠値にPER判定の基準が入る', function () {
        // Arrange: 業種「小売」設定済みの利確対象保有（含み益+25%・ボリンジャー過熱の価格推移・PER=950/120≒7.9）
        $snapshot = chg48fSnapshot();
        $batch = ImportBatch::findOrFail($snapshot->import_batch_id);
        $holding = Holding::create([
            'symbol_code' => '9101',
            'market' => 'jp',
            'instrument_type' => 'stock',
            'symbol_name' => '小売の利確対象',
            'sector_classification_id' => chg48fSectorId('jp', '小売'),
            'first_detected_at' => now(),
        ]);
        HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id,
            'holding_id' => $holding->id,
            'quantity' => 100,
            'average_cost' => 800,
            'current_price' => 950,
            'fx_rate_used' => null,
            'unrealized_gain_amount' => 15000,
            'unrealized_gain_rate' => 25.0,
            'ma20' => null,
            'ma75' => null,
            'is_newly_detected' => false,
        ]);
        $latest = [
            'net_sales' => 120000.0, 'operating_profit' => 15000.0, 'profit' => 10000.0, 'eps' => 120.0,
            'book_value_per_share' => 800.0, 'equity_to_asset_ratio' => 0.55, 'roe' => 0.125,
            'dividend_per_share_annual' => 30.0, 'payout_ratio_annual' => 0.30,
        ];
        $statements = [];
        foreach (['FY', '3Q', '2Q', '1Q'] as $i => $period) {
            $statements[] = array_merge($latest, ['disclosed_date' => "2026Q{$i}", 'period_type' => $period, 'fiscal_year_end' => '2026-03-31']);
        }
        $statements[] = array_merge($latest, [
            'disclosed_date' => '2025FY', 'period_type' => 'FY', 'fiscal_year_end' => '2025-03-31',
            'net_sales' => 100000.0, 'operating_profit' => 12000.0, 'eps' => 100.0,
        ]);

        app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient([
            '9101' => chg48fWeekly(array_merge(array_fill(0, 19, 100.0), [130.0])),
        ]));
        app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
        app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient([
            'nikkei225' => chg48fWeekly(array_map(fn (int $i) => 30000.0 + 5 * $i, range(0, 13)), 0),
            'sp500' => chg48fWeekly(array_map(fn (int $i) => 4500.0 + 20.0 * $i, range(0, 13)), 0),
        ]));
        app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient(statementsResponses: ['9101' => $statements]));
        app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

        // Act
        app(FetchExternalMarketDataAction::class)->execute($batch);

        // Assert（前提: 業種は維持され、シグナル発生記録が作られている）
        expect($holding->fresh()->sectorClassification?->name)->toBe('小売');
        $occurrence = SignalOccurrence::where('holding_id', $holding->id)->first();
        expect($occurrence)->not->toBeNull();
        expect($occurrence->metrics['per'])->not->toBeNull();

        // Assert
        expect($occurrence->metrics)->toHaveKeys(['per_basis', 'per_benchmark', 'per_factor', 'per_valuation_tier']);
        expect($occurrence->metrics['per_basis'])->toBe('sector');
        expect((float) $occurrence->metrics['per_benchmark'])->toBe(22.5);
        expect((float) $occurrence->metrics['per_factor'])->toBe(0.8);
        expect($occurrence->metrics['per_valuation_tier'])->toBe('strong_cheap'); // PER≒7.9 / 22.5 = 0.35（high）
    });
});

describe('UC-014 根拠値の表示: PER判定の基準のラベル（SignalOccurrenceMetricLabels）', function () {
    test('業種比較の記録は、基準・基準PER・しきい値の倍率・段階が日本語のラベルで表示される', function () {
        // Arrange
        $metrics = ['per' => 17.4, 'per_basis' => 'sector', 'per_benchmark' => 22.5, 'per_factor' => 0.8, 'per_valuation_tier' => 'cheap'];

        // Act
        $items = SignalOccurrenceMetricLabels::format($metrics);

        // Assert
        expect($items)->toContain('PER: 17.40');
        expect($items)->toContain('PER判定の基準: 業種比較');
        expect($items)->toContain('業種の基準PER: 22.50');
        expect($items)->toContain('基準比のしきい値: 0.8倍');
        expect($items)->toContain('業種比較の段階: 割安');
        expect($items)->toHaveCount(5);
    });

    test('信頼度が低い業種の倍率0.7は「0.7倍」、段階はValuationDisplayのラベルで表示される', function () {
        // Act
        $items = SignalOccurrenceMetricLabels::format(['per_basis' => 'sector', 'per_factor' => 0.7, 'per_valuation_tier' => 'strong_expensive']);

        // Assert
        expect($items)->toContain('基準比のしきい値: 0.7倍');
        expect($items)->toContain('業種比較の段階: 強い割高');
    });

    test('固定の基準の記録は「固定（PER≦15）」と表示され、nullの基準PER・倍率・段階は表示されない', function () {
        // Act
        $items = SignalOccurrenceMetricLabels::format(['per' => 14.0, 'per_basis' => 'fixed', 'per_benchmark' => null, 'per_factor' => null, 'per_valuation_tier' => null]);

        // Assert
        expect($items)->toBe(['PER: 14.00', 'PER判定の基準: 固定（PER≦15）']);
    });

    test('PER判定の基準のキーが無い変更前の記録は、従来どおり表示される', function () {
        // Act
        $items = SignalOccurrenceMetricLabels::format(['per' => 15.0, 'close' => 607.5, 'dividend_yield' => 3.0]);

        // Assert
        expect($items)->toBe(['終値: 607.50', 'PER: 15.00', '配当利回り: 3.00']);
    });
});

// ---------------------------------------------------------------------------
// 3. 見た目の修正
// ---------------------------------------------------------------------------

describe('判定チェックリストのチップ: 濃い背景のときの基準の行の文字色（ADR-0023 D4・D9 / ADR-0026 D2・D4）', function () {
    test('背景が濃い色のチップは、基準の行の文字色が白系になる', function (array $overrides) {
        // Act
        $class = chg48fThresholdClass(chg48fChipItem($overrides));

        // Assert
        expect($class)->not->toBeNull();
        expect($class)->toContain('text-white');
        expect($class)->not->toContain('text-text-secondary');
    })->with([
        '強い割安' => [['valuation' => ['tier' => 'strong_cheap', 'unstable' => false]]],
        '強い割高' => [['valuation' => ['tier' => 'strong_expensive', 'unstable' => false], 'status' => 'info']],
        '強い良好（達成）' => [['label' => 'ROE', 'value_label' => '16.0%', 'threshold_label' => '≧8.0%', 'strength' => 'strong_good', 'status' => 'met']],
    ]);

    test('背景が濃い色でないチップは、基準の行の文字色が従来どおりtext-text-secondaryのまま', function (array $overrides) {
        // Act
        $class = chg48fThresholdClass(chg48fChipItem($overrides));

        // Assert
        expect($class)->not->toBeNull();
        expect($class)->toContain('text-text-secondary');
        expect($class)->not->toContain('text-white');
    })->with([
        '割安（薄い緑）' => [['valuation' => ['tier' => 'cheap', 'unstable' => false]]],
        '強い割安だがラベルのみ（色なし）' => [['valuation' => ['tier' => 'strong_cheap', 'unstable' => false], 'label_only' => true, 'status' => 'info']],
        '強い良好だがあと一歩' => [['label' => 'ROE', 'value_label' => '7.0%', 'threshold_label' => '≧8.0%', 'strength' => 'strong_good', 'status' => 'near']],
        '通常の達成' => [['status' => 'met']],
        '未達' => [['status' => 'unmet']],
    ]);
});

describe('UC-003 銘柄詳細: 判定バッジの折り返しと基準欄の配置', function () {
    test('PER・PBRの判定バッジと健全性のバッジは折り返さない（whitespace-nowrap）', function () {
        // Arrange / Act
        $xp = chg48fDom(chg48fDetailHtml());

        // Assert
        foreach (['per-verdict', 'pbr-verdict', 'tone-roe', 'tone-equity_ratio', 'tone-operating_margin', 'tone-revenue_growth'] as $testid) {
            $badges = $xp->query('//*[@data-testid="'.$testid.'"]//span[contains(@class, "rounded")]');
            expect($badges->length)->toBeGreaterThan(0, "{$testid} のバッジがない");
            foreach ($badges as $badge) {
                expect($badge->getAttribute('class'))->toContain('whitespace-nowrap');
            }
        }
    });

    test('PER・PBRの基準欄は、指標の<dt>・<dd>の横並びの行の中ではなく、その下の独立した行に置かれる', function () {
        // Arrange / Act
        $html = chg48fDetailHtml();
        $xp = chg48fDom($html);

        // Assert
        foreach (['per-benchmark', 'pbr-benchmark'] as $testid) {
            $node = $xp->query('//*[@data-testid="'.$testid.'"]')->item(0);
            expect($node)->not->toBeNull("{$testid} がない");
            $parentDts = $xp->query('./dt', $node->parentNode);
            expect($parentDts->length)->toBe(0, "{$testid} の親要素が<dt>を子に持っている");
            expect(trim($node->textContent))->toContain('業種基準');
        }
        // 既存の表示（<dd>の中身）は変わらない
        expect($html)->toContain('<dd>17.4</dd>');
        expect($html)->toContain('<dd>1.3</dd>');
    });
});
