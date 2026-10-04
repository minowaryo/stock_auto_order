<?php

use App\Livewire\Signal\SignalList;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0034 サイクル3b: 判定チェックリストのチップ（x-criteria-chip）の見た目 — Red phase
|--------------------------------------------------------------------------
|
| 根拠: ADR-0023 D3・D4・D9 / ADR-0026 D1・D2・D4,
| use-cases.md UC-004・UC-010・UC-011・UC-013（CHG-0048）, ui-guidelines.md「判定チェックリストのチップ」。
| チップの項目データ（'valuation' / 'label_only' / 'strength'）は3aでGreen済み。
| ここではチップ部品の表示（段階ラベル・背景色・文字色）だけを固定する。
| 色の確認は、チップのルート要素の class 文字列の出現で行う。
| 関数名は他ファイルとの再宣言衝突を避けるため `chg34cc` 接頭辞。
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chg34ccItem(array $overrides = []): array
{
    return array_merge([
        'label' => 'PER',
        'value_label' => '10.0倍',
        'threshold_label' => '基準 25.0倍',
        'status' => 'info',
    ], $overrides);
}

function chg34ccDom(string $html): DOMXPath
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
    libxml_clear_errors();

    return new DOMXPath($doc);
}

/** チップ（部品）を描画し、DOM検索用のXPathとHTML文字列を返す。 */
function chg34ccRender(array $item, string $tone = 'success'): array
{
    $html = (string) test()->blade('<x-criteria-chip :item="$item" :tone="$tone" />', ['item' => $item, 'tone' => $tone]);

    return [$html, chg34ccDom($html)];
}

/** チップのルート要素（body直下の最初の要素）のclass文字列。 */
function chg34ccRootClass(DOMXPath $xp): string
{
    return (string) $xp->query('//body/*[1]')->item(0)?->getAttribute('class');
}

function chg34ccText(DOMXPath $xp, string $testid): string
{
    $node = $xp->query('//*[@data-testid="'.$testid.'"]')->item(0);

    // 要素が無い場合は空文字（未実装を「表示がない」失敗として扱う）
    return $node === null ? '' : (string) preg_replace('/\s+/u', '', $node->textContent);
}

// --- 1. 色付きの業種比較（valuation あり・label_only なし） ---

test('UC-004/ADR-0023 D3: 業種比較の段階に応じてチップの背景・文字色とラベルが変わる', function (string $tier, string $label, array $mustHave) {
    // Arrange
    $item = chg34ccItem(['valuation' => ['tier' => $tier, 'unstable' => false]]);

    // Act
    [, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccText($xp, 'chip-valuation'))->toContain($label);
    foreach ($mustHave as $class) {
        expect(chg34ccRootClass($xp))->toContain($class);
    }
})->with([
    '強い割安' => ['strong_cheap', '強い割安', ['bg-green-600', 'text-white']],
    '割安' => ['cheap', '割安', ['bg-green-100']],
    '並み' => ['fair', '並み', ['bg-slate-50']],
    '割高' => ['expensive', '割高', ['bg-amber-100']],
    '強い割高' => ['strong_expensive', '強い割高', ['bg-orange-600', 'text-white']],
]);

test('ADR-0023 D4: 基準が不安定な業種比較のチップは基準不安定の文字を段階ラベルと同じ要素に出す', function () {
    // Arrange
    $item = chg34ccItem(['valuation' => ['tier' => 'cheap', 'unstable' => true]]);

    // Act
    [, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccText($xp, 'chip-valuation'))->toContain('割安')->toContain('基準不安定');
});

test('ADR-0023 D4: 基準が安定した業種比較のチップには基準不安定の文字が出ない', function () {
    // Arrange
    $item = chg34ccItem(['valuation' => ['tier' => 'cheap', 'unstable' => false]]);

    // Act
    [, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccText($xp, 'chip-valuation'))->not->toContain('基準不安定');
});

// --- 2. 整理検討（label_only） ---

test('UC-011/ADR-0026 D2: 整理検討(label_only)のチップはラベル文字のみで段階の色を付けない', function (string $tier, string $label) {
    // Arrange
    $item = chg34ccItem(['valuation' => ['tier' => $tier, 'unstable' => false], 'label_only' => true]);

    // Act
    [, $xp] = chg34ccRender($item, 'danger');
    $class = chg34ccRootClass($xp);

    // Assert
    expect(chg34ccText($xp, 'chip-valuation'))->toContain($label);
    expect($class)->toContain('bg-slate-50')->toContain('text-slate-700');
    foreach (['bg-green-600', 'bg-green-100', 'bg-amber-100', 'bg-orange-600', 'text-white'] as $forbidden) {
        expect($class)->not->toContain($forbidden);
    }
})->with([
    '強い割安' => ['strong_cheap', '強い割安'],
    '割安' => ['cheap', '割安'],
    '割高' => ['expensive', '割高'],
    '強い割高' => ['strong_expensive', '強い割高'],
]);

test('UC-011/ADR-0026 D2: 整理検討(label_only)で基準が不安定なら基準不安定の文字だけは出る', function () {
    // Arrange
    $item = chg34ccItem(['valuation' => ['tier' => 'cheap', 'unstable' => true], 'label_only' => true]);

    // Act
    [, $xp] = chg34ccRender($item, 'danger');

    // Assert
    expect(chg34ccText($xp, 'chip-valuation'))->toContain('基準不安定');
});

// --- 3. valuation なし（回帰ガード） ---

test('UC-004: valuationがnullのPERチップは従来のニュートラル表示でchip-valuationを出さない', function () {
    // Arrange
    $item = chg34ccItem(['valuation' => null]);

    // Act
    [$html, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccRootClass($xp))->toContain('bg-slate-50')->toContain('text-slate-700');
    expect($html)->not->toContain('chip-valuation');
});

test('UC-010: valuationキーが無い(買い増し候補のPER)チップは達成状態に応じた従来の色でchip-valuationを出さない', function (string $status, string $class) {
    // Arrange
    $item = chg34ccItem(['status' => $status]);

    // Act
    [$html, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccRootClass($xp))->toContain($class);
    expect($html)->not->toContain('chip-valuation');
})->with([
    '達成' => ['met', 'bg-green-100'],
    'あと一歩' => ['near', 'bg-green-50'],
    '未達' => ['unmet', 'text-slate-500'],
    'データなし' => ['unavailable', 'text-slate-400'],
]);

// --- 4. 財務チップの強い良好 ---

test('UC-013/ADR-0026 D4: 達成(met)かつ強い良好の財務チップは濃い緑で強い良好を表示する', function () {
    // Arrange
    $item = chg34ccItem(['label' => 'ROE', 'status' => 'met', 'strength' => 'strong_good']);

    // Act
    [, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccRootClass($xp))->toContain('bg-green-600')->toContain('text-white');
    expect(chg34ccText($xp, 'chip-strength'))->toContain('強い良好');
});

test('ADR-0026 D4: 達成でない財務チップはstrengthが付いていても強調しない', function (string $status) {
    // Arrange
    $item = chg34ccItem(['label' => 'ROE', 'status' => $status, 'strength' => 'strong_good']);

    // Act
    [$html, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccRootClass($xp))->not->toContain('bg-green-600')->not->toContain('text-white');
    expect($html)->not->toContain('chip-strength');
})->with(['near', 'unmet', 'unavailable']);

test('ADR-0026 D4: strengthがnullの達成チップは従来の薄い緑のまま', function () {
    // Arrange
    $item = chg34ccItem(['label' => 'ROE', 'status' => 'met', 'strength' => null]);

    // Act
    [$html, $xp] = chg34ccRender($item);

    // Assert
    expect(chg34ccRootClass($xp))->toContain('bg-green-100')->not->toContain('bg-green-600');
    expect($html)->not->toContain('chip-strength');
});

test('ADR-0026 D4: toneがwarningのチップでも強い良好かつ達成なら濃い緑を優先する', function () {
    // Arrange
    $item = chg34ccItem(['label' => 'ROE', 'status' => 'met', 'strength' => 'strong_good', 'tone' => 'warning']);

    // Act
    [, $xp] = chg34ccRender($item, 'success');
    $class = chg34ccRootClass($xp);

    // Assert
    expect($class)->toContain('bg-green-600')->toContain('text-white')->not->toContain('bg-amber-100');
    expect(chg34ccText($xp, 'chip-strength'))->toContain('強い良好');
});

// --- 5. 既存の見た目（回帰ガード） ---

test('既存: 達成・あと一歩のtone別の色とラベル・値・基準の3行は変わらない', function (string $status, string $tone, string $class) {
    // Arrange
    $item = chg34ccItem(['label' => 'RSI', 'value_label' => '28.0', 'threshold_label' => '基準 30以下', 'status' => $status]);

    // Act
    [$html, $xp] = chg34ccRender($item, $tone);

    // Assert
    expect(chg34ccRootClass($xp))->toContain($class);
    expect($html)->toContain('RSI')->toContain('28.0')->toContain('基準 30以下');
})->with([
    '達成・success' => ['met', 'success', 'bg-green-100'],
    'あと一歩・success' => ['near', 'success', 'bg-green-50'],
    '達成・warning' => ['met', 'warning', 'bg-amber-100'],
    'あと一歩・warning' => ['near', 'warning', 'bg-amber-50'],
    '達成・danger' => ['met', 'danger', 'bg-red-100'],
    'あと一歩・danger' => ['near', 'danger', 'bg-red-50'],
]);

test('既存: item側のtoneが表のtoneより優先される(CHG-0046)', function () {
    // Arrange
    $item = chg34ccItem(['status' => 'met', 'tone' => 'warning']);

    // Act
    [, $xp] = chg34ccRender($item, 'success');

    // Assert
    expect(chg34ccRootClass($xp))->toContain('bg-amber-100');
});

// --- 6. 統合（売買シグナル画面） ---

/**
 * 日本・食品でPER9.0・PBR0.5の保有を1件作る（段階はどちらも強い割安）。
 *
 * @param  'take_profit'|'buy'|'loss'  $kind
 */
function chg34ccSeedHolding(string $kind): void
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp_stock.csv', 'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null, 'imported_count' => 0, 'error_count' => 0, 'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $holding = Holding::create([
        'symbol_code' => '9999', 'market' => 'jp', 'instrument_type' => 'stock', 'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => SectorClassification::firstOrCreate(['market' => 'jp', 'name' => '食品'], ['code' => null])->id,
        'first_detected_at' => now(),
    ]);

    [$price, $amount, $rate] = match ($kind) {
        'take_profit' => [1250, 25000, 25.0],
        'loss' => [600, -40000, -40.0],
        default => [1050, 5000, 5.0],
    };
    $holdingSnapshot = HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id, 'quantity' => 100, 'average_cost' => 1000,
        'current_price' => $price, 'fx_rate_used' => null, 'unrealized_gain_amount' => $amount,
        'unrealized_gain_rate' => $rate, 'ma20' => null, 'ma75' => null, 'is_newly_detected' => false,
    ]);
    TechnicalIndicator::create([
        'holding_id' => $holding->id, 'rsi' => 50.0, 'macd' => 1.0, 'macd_signal' => 0.5,
        'ma20' => 1000.0, 'ma75' => 950.0, 'bb_upper' => 1100.0, 'bb_lower' => 900.0,
        'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0, 'week52_high' => 1200.0, 'week52_low' => 800.0,
        'relative_strength_vs_market' => 6.0, 'relative_strength_vs_sector' => 6.0, 'computed_at' => now(),
    ]);
    FundamentalIndicator::create([
        'holding_id' => $holding->id, 'per' => 9.0, 'pbr' => 0.5, 'roe' => 12.0, 'revenue_growth' => 5.0,
        'operating_income_growth' => 5.0, 'equity_ratio' => 45.0, 'operating_margin' => 12.0,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ]);

    if ($kind === 'take_profit') {
        Signal::create(['holding_snapshot_id' => $holdingSnapshot->id, 'signal_type' => 'rsi_reversal', 'reason_summary' => 'RSIが72から65に反落']);
    }
    if ($kind === 'buy') {
        BuySignal::create(['holding_snapshot_id' => $holdingSnapshot->id, 'signal_type' => 'rsi_oversold_rebound', 'reason_summary' => 'RSIが28から34へ反発しました']);
    }
}

/** 見出し文言（正規表現）から次の<h2>までのセクションHTMLを切り出す。 */
function chg34ccSection(string $html, string $headingPattern): string
{
    expect(preg_match('/<h2[^>]*>\s*'.$headingPattern.'.*?(?=<h2|\z)/su', $html, $m))->toBe(1, "見出し {$headingPattern} のセクションが見つかりません");

    return $m[0];
}

/** セクション内の指定ラベルのチップ（ラベルspanを含む<td>の中身）を返す。 */
function chg34ccChipHtml(string $section, string $label): string
{
    expect(preg_match('/<td[^>]*>\s*<div[^>]*rounded border[^>]*>(?:(?!<\/td>).)*?<span[^>]*>\s*'.preg_quote($label, '/').'\s*<\/span>.*?<\/td>/su', $section, $m))->toBe(1, "チップ {$label} が見つかりません");

    return $m[0];
}

function chg34ccScreen(): string
{
    return Livewire::actingAs(User::factory()->create())->test(SignalList::class)->html();
}

test('UC-004/CHG-0048: 利確検討の表のPER・PBRチップに強い割安が出て濃い緑になる', function () {
    // Arrange
    chg34ccSeedHolding('take_profit');

    // Act
    $section = chg34ccSection(chg34ccScreen(), '利確検討');

    // Assert
    foreach (['PER', 'PBR'] as $label) {
        $chip = chg34ccChipHtml($section, $label);
        expect($chip)->toContain('chip-valuation')->toContain('強い割安')->toContain('bg-green-600');
    }
});

test('UC-011/CHG-0048: 整理検討の表のPERチップは強い割安の文字が出るが濃い緑にはならない', function () {
    // Arrange
    chg34ccSeedHolding('loss');

    // Act
    $section = chg34ccSection(chg34ccScreen(), '整理検討');
    $chip = chg34ccChipHtml($section, 'PER');

    // Assert
    expect($chip)->toContain('chip-valuation')->toContain('強い割安')->not->toContain('bg-green-600');
});

test('UC-010/CHG-0048: 買い増し候補の表はPBRチップに強い割安が出て、PERチップにはchip-valuationが出ない', function () {
    // Arrange
    chg34ccSeedHolding('buy');

    // Act
    $section = chg34ccSection(chg34ccScreen(), '買い増し候補');
    $pbr = chg34ccChipHtml($section, 'PBR');
    $per = chg34ccChipHtml($section, 'PER');

    // Assert
    expect($pbr)->toContain('chip-valuation')->toContain('強い割安');
    expect($per)->not->toContain('chip-valuation');
});
