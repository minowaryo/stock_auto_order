<?php

use App\Actions\Portfolio\ShowHoldListAction;
use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\WatchlistItem;

/*
|--------------------------------------------------------------------------
| CHG-0034 サイクル3a: 売買シグナル画面の判定チェックリスト（データ層）— Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/adr/ADR-0023 D3-D9 / ADR-0026 D1-D4,
| docs/product/use-cases.md UC-004・UC-010・UC-011・UC-012・UC-013（CHG-0048）。
|
| 5つのAction（利確検討・買い増し候補・整理検討・キープ・ウォッチリスト）が返す行の
| criteria に、PER/PBRチップの 'valuation'（整理検討は 'label_only' も）と、財務チップの
| 'strength' が載ることを固定する。チップの見た目（Blade）は次のサイクル3b。
| 買い増し候補・ウォッチリストのPERチップは今回変えない（'valuation' キー無しの回帰ガード）。
|
| 各Actionの行の出し方は既存テスト（UC004/UC010/UC011/CHG0046/UC012WatchlistScreen）を踏襲:
|   利確検討: 含み益率+25%・シグナル1件（高水準モード+150%を避ける）
|   買い増し候補: 買い増しシグナル1件・利確シグナル0件・財務健全
|   整理検討: 含み損率-40%
|   キープ: 含み益率+5%・シグナル無し（ClassifyHoldingsAction の hold バケツ）
|   ウォッチリスト: 直近スナップショット無し（未保有）の WatchlistItem
|
| Fixtures use a unique `chg34sc` prefix to avoid cross-file function redeclaration.
*/

/**
 * @param  array<string, mixed>  $fundamental  既定は財務健全（passed）の値
 * @return array<string, mixed>
 */
function chg34scFundamental(array $fundamental = []): array
{
    return array_merge([
        'per' => null, 'pbr' => null, 'roe' => 12.0, 'revenue_growth' => 5.0,
        'operating_income_growth' => 5.0, 'equity_ratio' => 45.0, 'operating_margin' => 12.0,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ], $fundamental);
}

function chg34scSector(string $market, ?string $name): ?int
{
    return $name === null
        ? null
        : SectorClassification::firstOrCreate(['market' => $market, 'name' => $name], ['code' => null])->id;
}

/**
 * @param  array<string, mixed>  $technical
 */
function chg34scTechnical(Holding $holding, array $technical = []): void
{
    TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 50.0, 'macd' => 1.0, 'macd_signal' => 0.5,
        'ma20' => 1000.0, 'ma75' => 950.0, 'bb_upper' => 1100.0, 'bb_lower' => 900.0,
        'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0,
        'week52_high' => 1200.0, 'week52_low' => 800.0,
        'relative_strength_vs_market' => 6.0, 'relative_strength_vs_sector' => 6.0,
        'computed_at' => now(),
    ], $technical));
}

/**
 * 保有銘柄を直近スナップショットに作る。
 *
 * @param  'take_profit'|'buy'|'loss'|'hold'  $kind
 * @param  array<string, mixed>  $fundamental
 */
function chg34scHolding(string $kind, string $market, ?string $sector, array $fundamental = []): Holding
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
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);

    $holding = Holding::create([
        'symbol_code' => '9999',
        'market' => $market,
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => chg34scSector($market, $sector),
        'first_detected_at' => now(),
    ]);

    [$price, $amount, $rate] = match ($kind) {
        'take_profit' => [1250, 25000, 25.0],
        'loss' => [600, -40000, -40.0],
        default => [1050, 5000, 5.0],
    };

    $holdingSnapshot = HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => $price,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => $amount,
        'unrealized_gain_rate' => $rate,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);

    chg34scTechnical($holding);
    FundamentalIndicator::create(array_merge(['holding_id' => $holding->id], chg34scFundamental($fundamental)));

    if ($kind === 'take_profit') {
        Signal::create([
            'holding_snapshot_id' => $holdingSnapshot->id,
            'signal_type' => 'rsi_reversal',
            'reason_summary' => 'RSIが72から65に反落',
        ]);
    }
    if ($kind === 'buy') {
        BuySignal::create([
            'holding_snapshot_id' => $holdingSnapshot->id,
            'signal_type' => 'rsi_oversold_rebound',
            'reason_summary' => 'RSIが28から34へ反発しました',
        ]);
    }

    return $holding;
}

/**
 * 未保有のウォッチリスト銘柄（直近スナップショットが無いので一覧に出る）。
 *
 * @param  array<string, mixed>  $fundamental
 */
function chg34scWatchlist(string $market, ?string $sector, array $fundamental = []): WatchlistItem
{
    $holding = Holding::create([
        'symbol_code' => '9999',
        'market' => $market,
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => chg34scSector($market, $sector),
        'first_detected_at' => now(),
    ]);
    chg34scTechnical($holding);
    FundamentalIndicator::create(array_merge(['holding_id' => $holding->id], chg34scFundamental($fundamental)));

    return WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => 'テーマA',
        'exchange_label' => '東Ｐ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => false,
        'last_close' => 1500.0,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);
}

/** @return array<string, mixed> 日本・食品で全財務チップが強い良好になる値（ROE16・自己資本70・営業利益率25・売上成長20） */
function chg34scStrongFundamental(array $extra = []): array
{
    return array_merge(['roe' => 16.0, 'equity_ratio' => 75.0, 'operating_margin' => 25.0, 'revenue_growth' => 20.0, 'operating_income_growth' => 5.0], $extra);
}

/** @param array<string, mixed> $row */
function chg34scChip(array $row, string $group, string $label): array
{
    $chip = collect($row['criteria'][$group])->firstWhere('label', $label);
    expect($chip)->not->toBeNull("チップ {$label} が見つかりません");

    return $chip;
}

/** 各Actionの行（銘柄コード 9999）を取り出す。 */
function chg34scRow(string $kind): array
{
    $rows = match ($kind) {
        'take_profit' => app(ShowSignalListAction::class)->execute(),
        'buy' => app(ShowBuySignalListAction::class)->execute(),
        'loss' => app(ShowLossReviewListAction::class)->execute(),
        'hold' => app(ShowHoldListAction::class)->execute(),
        'watchlist' => app(ShowWatchlistAction::class)->execute(),
    };
    $row = collect($rows)->firstWhere('symbol_code', '9999');
    expect($row)->not->toBeNull("{$kind} の行が出力されていません");

    return $row;
}

function chg34scSeed(string $kind, string $market, ?string $sector, array $fundamental = []): void
{
    $kind === 'watchlist'
        ? chg34scWatchlist($market, $sector, $fundamental)
        : chg34scHolding($kind, $market, $sector, $fundamental);
}

// --- PER・PBRチップの valuation ---

test('利確検討(UC-004): 日本・食品でPER10.0・PBR0.9はPER・PBRチップとも強い割安の判定を持つ', function () {
    // Arrange
    chg34scSeed('take_profit', 'jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    expect(chg34scChip($row, 'technical', 'PER')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
});

test('利確検討(UC-004): 業種未分類の銘柄のPER・PBRチップは valuation が null', function () {
    // Arrange
    chg34scSeed('take_profit', 'jp', null, ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    foreach (['PER', 'PBR'] as $label) {
        $chip = chg34scChip($row, 'technical', $label);
        expect(array_key_exists('valuation', $chip))->toBeTrue();
        expect($chip['valuation'])->toBeNull();
    }
});

test('利確検討(UC-004): 米国・SemiconductorsはPERを基準表で判定し、PBRは判定なしになる', function () {
    // Arrange（基準PER 44.8。22.4 は比率0.50＝割安）
    chg34scSeed('take_profit', 'us', 'Semiconductors', ['per' => 22.4, 'pbr' => 5.0]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    expect(chg34scChip($row, 'technical', 'PER')['valuation'])->toBe(['tier' => 'cheap', 'unstable' => false]);
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBeNull();
});

test('利確検討(UC-004): 基準の信頼度が低い業種(電機・精密)は unstable が true で、強い割安は割安に格下げされる', function () {
    // Arrange（基準PER 43.5・PBR 3.64、ともに confidence low。PER20.0=0.46 / PBR1.0=0.27 は本来強い割安）
    chg34scSeed('take_profit', 'jp', '電機・精密', ['per' => 20.0, 'pbr' => 1.0]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    expect(chg34scChip($row, 'technical', 'PER')['valuation'])->toBe(['tier' => 'cheap', 'unstable' => true]);
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'cheap', 'unstable' => true]);
});

test('利確検討(UC-004): 基準表の設定を差し替えるとチップの判定に反映される', function () {
    // Arrange（食品のPER基準を10.0にすると、PER10.0は比率1.0＝並み）
    config(['valuation_benchmarks' => [
        'jp' => [
            'as_of' => '2030-01',
            'source' => 'test',
            'per' => ['食品' => ['value' => 10.0, 'confidence' => 'high']],
            'pbr' => [],
        ],
    ]]);
    chg34scSeed('take_profit', 'jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    expect(chg34scChip($row, 'technical', 'PER')['valuation'])->toBe(['tier' => 'fair', 'unstable' => false]);
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBeNull();
});

test('キープ(UC-013): PER・PBRチップは valuation を持つ', function () {
    // Arrange
    chg34scSeed('hold', 'jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('hold');

    // Assert
    expect(chg34scChip($row, 'technical', 'PER')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
});

test('キープ(UC-013): 業種未分類のPER・PBRチップは valuation が null', function () {
    // Arrange
    chg34scSeed('hold', 'jp', null, ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('hold');

    // Assert
    foreach (['PER', 'PBR'] as $label) {
        $chip = chg34scChip($row, 'technical', $label);
        expect(array_key_exists('valuation', $chip))->toBeTrue();
        expect($chip['valuation'])->toBeNull();
    }
});

test('整理検討(UC-011): PER・PBRチップは valuation と label_only を持つ', function () {
    // Arrange（PER35.0=1.49倍＝割高、PBR0.9=0.48倍＝強い割安）
    chg34scSeed('loss', 'jp', '食品', ['per' => 35.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('loss');

    // Assert
    $per = chg34scChip($row, 'technical', 'PER');
    $pbr = chg34scChip($row, 'technical', 'PBR');
    expect($per['valuation'])->toBe(['tier' => 'expensive', 'unstable' => false]);
    expect($per['label_only'])->toBeTrue();
    expect($pbr['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
    expect($pbr['label_only'])->toBeTrue();
});

test('整理検討(UC-011): 業種未分類のPER・PBRチップは valuation が null', function () {
    // Arrange
    chg34scSeed('loss', 'jp', null, ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('loss');

    // Assert
    foreach (['PER', 'PBR'] as $label) {
        $chip = chg34scChip($row, 'technical', $label);
        expect(array_key_exists('valuation', $chip))->toBeTrue();
        expect($chip['valuation'])->toBeNull();
    }
});

test('買い増し候補(UC-010): PBRチップだけが valuation を持ち、PERチップは従来どおり valuation キーを持たない', function () {
    // Arrange
    chg34scSeed('buy', 'jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('buy');

    // Assert
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
    expect(array_key_exists('valuation', chg34scChip($row, 'technical', 'PER')))->toBeFalse();
});

test('ウォッチリスト(UC-012): 買い増し候補と同じく PBRチップだけが valuation を持つ', function () {
    // Arrange
    chg34scSeed('watchlist', 'jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $row = chg34scRow('watchlist');

    // Assert
    expect(chg34scChip($row, 'technical', 'PBR')['valuation'])->toBe(['tier' => 'strong_cheap', 'unstable' => false]);
    expect(array_key_exists('valuation', chg34scChip($row, 'technical', 'PER')))->toBeFalse();
});

test('ウォッチリスト(UC-012): 米国・SemiconductorsのPBRは判定なし(valuation null)', function () {
    // Arrange
    chg34scSeed('watchlist', 'us', 'Semiconductors', ['per' => 22.4, 'pbr' => 5.0]);

    // Act
    $row = chg34scRow('watchlist');

    // Assert
    $chip = chg34scChip($row, 'technical', 'PBR');
    expect(array_key_exists('valuation', $chip))->toBeTrue();
    expect($chip['valuation'])->toBeNull();
});

// --- 財務チップの strength ---

test('利確検討(UC-004): ROE16.0で達成(met)のROEチップは strength が strong_good、基準を満たすだけの自己資本比率は null', function () {
    // Arrange（日本のROE強い良好ライン15.0。自己資本比率45.0は良好止まり）
    chg34scSeed('take_profit', 'jp', '食品', ['roe' => 16.0, 'equity_ratio' => 45.0]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    $roe = chg34scChip($row, 'fundamental', 'ROE');
    expect($roe['status'])->toBe('met');
    expect($roe['strength'])->toBe('strong_good');
    expect(chg34scChip($row, 'fundamental', '自己資本比率')['strength'])->toBeNull();
});

test('利確検討(UC-004): 達成(met)でない財務チップは strength が null', function () {
    // Arrange（ROE5.0は基準未達）
    chg34scSeed('take_profit', 'jp', '食品', ['roe' => 5.0]);

    // Act
    $row = chg34scRow('take_profit');

    // Assert
    $roe = chg34scChip($row, 'fundamental', 'ROE');
    expect($roe['status'])->not->toBe('met');
    expect($roe['strength'])->toBeNull();
});

test('買い増し候補・キープ・ウォッチリスト・利確検討: 財務4チップが強い良好のとき strength が strong_good になる', function (string $kind) {
    // Arrange
    chg34scSeed($kind, 'jp', '食品', chg34scStrongFundamental());

    // Act
    $row = chg34scRow($kind);

    // Assert
    foreach (['ROE', '自己資本比率', '営業利益率', '成長率'] as $label) {
        $chip = chg34scChip($row, 'fundamental', $label);
        expect($chip['status'])->toBe('met');
        expect($chip['strength'])->toBe('strong_good');
    }
})->with(['take_profit', 'buy', 'hold', 'watchlist']);

test('成長率チップの strength は売上成長率と営業利益成長率の高い側のtoneで決まる(Action経由)', function () {
    // Arrange（売上成長率10.0=日本の強い良好15.0未満で良好、営業利益成長率40.0=強い良好30.0以上。高い方は営業利益側）
    chg34scSeed('hold', 'jp', '食品', chg34scStrongFundamental(['revenue_growth' => 10.0, 'operating_income_growth' => 40.0]));

    // Act
    $row = chg34scRow('hold');

    // Assert
    expect(chg34scChip($row, 'fundamental', '成長率')['strength'])->toBe('strong_good');
});

test('整理検討(UC-011): 財務チップには strength を付けない（赤の単一極性）', function () {
    // Arrange
    chg34scSeed('loss', 'jp', '食品', chg34scStrongFundamental());

    // Act
    $row = chg34scRow('loss');

    // Assert
    foreach (['ROE', '自己資本比率', '営業利益率', '成長率'] as $label) {
        expect(chg34scChip($row, 'fundamental', $label)['strength'] ?? null)->toBeNull();
    }
});

test('銀行の保有では自己資本比率・営業利益率の強い良好は付かず、ROEには付く（判定対象外の業種）', function () {
    // Arrange
    chg34scSeed('hold', 'jp', '銀行', chg34scStrongFundamental());

    // Act
    $row = chg34scRow('hold');

    // Assert
    expect(chg34scChip($row, 'fundamental', 'ROE')['strength'])->toBe('strong_good');
    expect(chg34scChip($row, 'fundamental', '自己資本比率')['strength'])->toBeNull();
    expect(chg34scChip($row, 'fundamental', '営業利益率')['strength'])->toBeNull();
});
