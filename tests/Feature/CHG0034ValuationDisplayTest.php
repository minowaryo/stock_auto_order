<?php

use App\Livewire\Holding\HoldingDetail;
use App\Livewire\Holding\HoldingList;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\User;
use Livewire\Livewire;

/*
| UC-002 / UC-003 / CHG-0034 サイクル2b: 保有一覧・銘柄詳細の画面(Blade)に
| 業種比較の割安・割高の判定と健全性指標の二段階の色付けを表示する — Red phase
| 根拠: ADR-0023 D3-D6・D9, use-cases.md UC-002・UC-003
| Actionは判定結果を返す(Green済み)。ここではビューの表示だけを固定する。
*/

/**
 * @param  array<string, mixed>  $fundamental  null to skip the fundamental row
 */
function chg34Holding(string $market, ?string $sector, ?array $fundamental, string $type = 'stock'): Holding
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
    $sectorId = $sector === null ? null : SectorClassification::firstOrCreate(['market' => $market, 'name' => $sector], ['code' => null])->id;

    $holding = Holding::create([
        'symbol_code' => '9999',
        'market' => $market,
        'instrument_type' => $type,
        'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => $sectorId,
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
    if ($fundamental !== null) {
        FundamentalIndicator::create(array_merge([
            'holding_id' => $holding->id,
            'per' => null, 'pbr' => null, 'roe' => null, 'revenue_growth' => null,
            'operating_income_growth' => null, 'equity_ratio' => null, 'operating_margin' => null,
            'dividend_yield' => null, 'dividend_payout_ratio' => null, 'fetched_at' => now(),
        ], $fundamental));
    }

    return $holding->fresh();
}

function chg34ListHtml(): string
{
    return Livewire::actingAs(User::factory()->create())->test(HoldingList::class)->html();
}

function chg34DetailHtml(Holding $holding): string
{
    return Livewire::actingAs(User::factory()->create())->test(HoldingDetail::class, ['holding' => $holding])->html();
}

function chg34Xpath(string $html): DOMXPath
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

/** class attribute of the first <span> whose normalized text equals $text, or null when absent. */
function chg34BadgeClass(string $html, string $text): ?string
{
    foreach (chg34Xpath($html)->query('//span') as $node) {
        if (trim(preg_replace('/\s+/u', ' ', $node->textContent)) === $text) {
            return $node->getAttribute('class');
        }
    }

    return null;
}

/** @return array{text: string, html: string}|null element identified by data-testid (text normalized, html = inner HTML) */
function chg34Testid(string $html, string $testid): ?array
{
    $xpath = chg34Xpath($html);
    $nodes = $xpath->query('//*[@data-testid="'.$testid.'"]');
    if ($nodes->length === 0) {
        return null;
    }
    $node = $nodes->item(0);
    $inner = '';
    foreach ($node->childNodes as $child) {
        $inner .= $node->ownerDocument->saveHTML($child);
    }

    return [
        'text' => trim(preg_replace('/\s+/u', ' ', $node->textContent)),
        'html' => $inner,
    ];
}

// --- バッジ部品 ---

test('バッジ部品は濃い緑(success-strong)と濃いオレンジ(warning-strong)のバリアントを持つ', function () {
    // Act / Assert
    $this->blade('<x-badge variant="success-strong">x</x-badge>')
        ->assertSeeHtml('bg-green-600')
        ->assertSeeHtml('text-white');
    $this->blade('<x-badge variant="warning-strong">x</x-badge>')
        ->assertSeeHtml('bg-orange-600')
        ->assertSeeHtml('text-white');
});

test('バッジ部品の既存バリアントの色は変わらない', function (string $variant, string $expected) {
    // Act / Assert
    $this->blade('<x-badge variant="'.$variant.'">x</x-badge>')->assertSeeHtml($expected);
})->with([
    ['success', 'bg-green-100 text-green-700'],
    ['danger', 'bg-red-100 text-red-700'],
    ['warning', 'bg-amber-100 text-amber-700'],
    ['info', 'bg-blue-50 text-primary'],
    ['neutral', 'bg-slate-100 text-text-secondary'],
]);

// --- 保有一覧 (UC-002) ---

test('保有一覧: 日本・食品でPER10.0の個別株は「PER 10.0 強い割安」を濃い緑で表示する', function () {
    // Arrange
    chg34Holding('jp', '食品', ['per' => 10.0]);

    // Act
    $class = chg34BadgeClass(chg34ListHtml(), 'PER 10.0 強い割安');

    // Assert
    expect($class)->not->toBeNull();
    expect($class)->toContain('bg-green-600')->toContain('text-white');
});

test('保有一覧: PERの段階ごとにラベルと色が対応する', function (float $per, string $label, string $color) {
    // Arrange (日本・食品の基準 23.5。比率: 15.0=0.64 / 23.5=1.00 / 35.0=1.49 / 60.0=2.55)
    chg34Holding('jp', '食品', ['per' => $per]);

    // Act
    $class = chg34BadgeClass(chg34ListHtml(), 'PER '.number_format($per, 1).' '.$label);

    // Assert
    expect($class)->not->toBeNull();
    expect($class)->toContain($color);
})->with([
    '割安' => [15.0, '割安', 'bg-green-100'],
    '並み' => [23.5, '並み', 'bg-slate-100'],
    '割高' => [35.0, '割高', 'bg-amber-100'],
    '強い割高' => [60.0, '強い割高', 'bg-orange-600'],
]);

test('保有一覧: 基準の信頼度が低い業種(電機・精密)のPER20.0は濃い色にならず「割安」を緑で表示する', function () {
    // Arrange
    chg34Holding('jp', '電機・精密', ['per' => 20.0]);

    // Act
    $class = chg34BadgeClass(chg34ListHtml(), 'PER 20.0 割安');

    // Assert
    expect($class)->not->toBeNull();
    expect($class)->toContain('bg-green-100')->not->toContain('bg-green-600');
});

test('保有一覧: 米国・SemiconductorsでPER22.4は「PER 22.4 割安」を表示する', function () {
    // Arrange
    chg34Holding('us', 'Semiconductors', ['per' => 22.4]);

    // Act
    $class = chg34BadgeClass(chg34ListHtml(), 'PER 22.4 割安');

    // Assert
    expect($class)->not->toBeNull();
    expect($class)->toContain('bg-green-100');
});

test('保有一覧: 業種未分類の個別株は従来どおり「PER 値」のneutralバッジで、判定ラベルは出ない', function () {
    // Arrange
    chg34Holding('jp', null, ['per' => 10.0]);

    // Act
    $html = chg34ListHtml();

    // Assert
    $class = chg34BadgeClass($html, 'PER 10.0');
    expect($class)->not->toBeNull();
    expect($class)->toContain('bg-slate-100');
    expect($html)->not->toContain('強い割安')->not->toContain('割安');
});

test('保有一覧: 売上成長の色調ごとにラベルと色が対応する', function (string $market, float $growth, string $text, string $color) {
    // Arrange
    chg34Holding($market, $market === 'jp' ? '食品' : 'Semiconductors', ['revenue_growth' => $growth]);

    // Act
    $class = chg34BadgeClass(chg34ListHtml(), $text);

    // Assert
    expect($class)->not->toBeNull();
    expect($class)->toContain($color);
})->with([
    '日本16.0は強い良好' => ['jp', 16.0, '売上成長 16.0% 強い良好', 'bg-green-600'],
    '日本5.0は良好' => ['jp', 5.0, '売上成長 5.0% 良好', 'bg-green-100'],
    '日本-5.0は基準未満' => ['jp', -5.0, '売上成長 -5.0% 基準未満', 'bg-amber-100'],
    '日本-12.0は大きく下回る' => ['jp', -12.0, '売上成長 -12.0% 大きく下回る', 'bg-orange-600'],
]);

test('保有一覧: 売上成長がnullの個別株は売上成長バッジを出さない', function () {
    // Arrange
    chg34Holding('jp', '食品', ['per' => 10.0, 'revenue_growth' => null]);

    // Act
    $html = chg34ListHtml();

    // Assert
    expect($html)->not->toContain('売上成長');
});

test('保有一覧: ETF・投資信託の行は従来どおり「対象外」で判定ラベルやバッジが出ない', function (string $type) {
    // Arrange
    chg34Holding('jp', '食品', ['per' => 10.0, 'revenue_growth' => 16.0], $type);

    // Act
    $html = chg34ListHtml();

    // Assert
    expect($html)->toContain('対象外');
    expect($html)->not->toContain('強い割安')->not->toContain('強い良好')->not->toContain('PER ');
})->with(['etf', 'mutual_fund']);

test('保有一覧: 基準表の設定を差し替えると表示の判定に反映される', function () {
    // Arrange
    config(['valuation_benchmarks' => [
        'jp' => [
            'as_of' => '2030-01',
            'source' => 'test',
            'per' => ['食品' => ['value' => 10.0, 'confidence' => 'high']],
            'pbr' => [],
        ],
    ]]);
    chg34Holding('jp', '食品', ['per' => 10.0]);

    // Act
    $class = chg34BadgeClass(chg34ListHtml(), 'PER 10.0 並み');

    // Assert
    expect($class)->not->toBeNull();
    expect($class)->toContain('bg-slate-100');
});

// --- 銘柄詳細 (UC-003) ---

test('詳細: 日本・食品でPER10.0・PBR0.9はPER・PBRとも「強い割安」を濃い緑のバッジで表示する', function () {
    // Arrange
    $holding = chg34Holding('jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    $per = chg34Testid($html, 'per-verdict');
    $pbr = chg34Testid($html, 'pbr-verdict');
    expect($per['text'])->toContain('強い割安');
    expect($per['html'])->toContain('bg-green-600');
    expect($pbr['text'])->toContain('強い割安');
    expect($pbr['html'])->toContain('bg-green-600');
});

test('詳細: 判定の段階ごとにラベルと色が対応する', function (float $per, string $label, string $color) {
    // Arrange (日本・食品のPER基準 23.5)
    $holding = chg34Holding('jp', '食品', ['per' => $per]);

    // Act
    $el = chg34Testid(chg34DetailHtml($holding), 'per-verdict');

    // Assert
    expect($el['text'])->toContain($label);
    expect($el['html'])->toContain($color);
})->with([
    '割安' => [15.0, '割安', 'bg-green-100'],
    '並み' => [23.5, '並み', 'bg-slate-100'],
    '割高' => [35.0, '割高', 'bg-amber-100'],
    '強い割高' => [60.0, '強い割高', 'bg-orange-600'],
]);

test('詳細: PER・PBRの判定は<dd>の外側に置かれ、<dd>の値は変わらない', function () {
    // Arrange
    $holding = chg34Holding('jp', '食品', ['per' => 10.0, 'pbr' => 1.3]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    expect($html)->toContain('<dd>1.3</dd>')->toContain('<dd>10.0</dd>');
    $xpath = chg34Xpath($html);
    expect($xpath->query('//dd//*[@data-testid="per-verdict"]')->length)->toBe(0);
    expect($xpath->query('//dd//*[@data-testid="pbr-verdict"]')->length)->toBe(0);
});

test('詳細: 基準値・基準比・基準日・出典を表示する(PER・PBR)', function () {
    // Arrange
    $holding = chg34Holding('jp', '食品', ['per' => 10.0, 'pbr' => 0.9]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    $per = chg34Testid($html, 'per-benchmark');
    expect($per['text'])
        ->toContain('業種基準 23.5')
        ->toContain('0.43倍')
        ->toContain('基準日 '.config('valuation_benchmarks.jp.as_of'))
        ->toContain('出典 '.config('valuation_benchmarks.jp.source'));
    $pbr = chg34Testid($html, 'pbr-benchmark');
    expect($pbr['text'])
        ->toContain('業種基準 1.86')
        ->toContain('0.48倍')
        ->toContain('基準日 '.config('valuation_benchmarks.jp.as_of'));
});

test('詳細: 基準が不安定な業種(電機・精密)は「基準が不安定」を表示し、濃い色にならない', function () {
    // Arrange
    $holding = chg34Holding('jp', '電機・精密', ['per' => 20.0]);

    // Act
    $el = chg34Testid(chg34DetailHtml($holding), 'per-verdict');

    // Assert
    expect($el['text'])->toContain('割安')->toContain('基準が不安定');
    expect($el['html'])->toContain('bg-green-100')->not->toContain('bg-green-600');
});

test('詳細: 基準が安定している業種には「基準が不安定」を表示しない', function () {
    // Arrange
    $holding = chg34Holding('jp', '食品', ['per' => 10.0]);

    // Act
    $el = chg34Testid(chg34DetailHtml($holding), 'per-verdict');

    // Assert
    expect($el['text'])->not->toContain('基準が不安定');
});

test('詳細: 判定なしの理由ごとの文言を表示し、バッジも基準欄も出さない', function (string $market, ?string $sector, array $fundamental, string $testid, string $message) {
    // Arrange
    $holding = chg34Holding($market, $sector, $fundamental);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    $el = chg34Testid($html, $testid.'-verdict');
    expect($el['text'])->toContain($message);
    expect($el['html'])->not->toContain('<span');
    expect(chg34Testid($html, $testid.'-benchmark'))->toBeNull();
})->with([
    'PER負' => ['jp', '食品', ['per' => -8.0, 'pbr' => 0.9], 'per', '値が0以下のため判定なし'],
    '業種未分類(PER)' => ['jp', null, ['per' => 10.0, 'pbr' => 0.9], 'per', '業種が未分類のため判定なし'],
    '業種未分類(PBR)' => ['jp', null, ['per' => 10.0, 'pbr' => 0.9], 'pbr', '業種が未分類のため判定なし'],
    '米国SemiconductorsのPBR' => ['us', 'Semiconductors', ['per' => 22.4, 'pbr' => 5.0], 'pbr', 'この業種は基準表にないため判定なし'],
    '米国AutomobilesのPER' => ['us', 'Automobiles', ['per' => 15.0, 'pbr' => 2.0], 'per', 'この業種は基準の対象外のため判定なし'],
]);

test('詳細: 健全性指標の色調ごとにtone要素へラベルと色をバッジで表示する', function () {
    // Arrange
    $holding = chg34Holding('jp', '食品', [
        'roe' => 16.0, 'equity_ratio' => 45.0, 'operating_margin' => 5.0, 'revenue_growth' => -12.0,
    ]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    $roe = chg34Testid($html, 'tone-roe');
    expect($roe['text'])->toContain('強い良好');
    expect($roe['html'])->toContain('bg-green-600');
    $equity = chg34Testid($html, 'tone-equity_ratio');
    expect($equity['text'])->toContain('良好');
    expect($equity['html'])->toContain('bg-green-100');
    $margin = chg34Testid($html, 'tone-operating_margin');
    expect($margin['text'])->toContain('基準未満');
    expect($margin['html'])->toContain('bg-amber-100');
    $growth = chg34Testid($html, 'tone-revenue_growth');
    expect($growth['text'])->toContain('大きく下回る');
    expect($growth['html'])->toContain('bg-orange-600');
});

test('詳細: 銀行の保有では自己資本比率と営業利益率のtone要素は出ず、ROEは出る', function () {
    // Arrange
    $holding = chg34Holding('jp', '銀行', [
        'roe' => 16.0, 'equity_ratio' => 5.0, 'operating_margin' => 10.0, 'revenue_growth' => 5.0,
    ]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    expect(chg34Testid($html, 'tone-roe'))->not->toBeNull();
    expect(chg34Testid($html, 'tone-equity_ratio'))->toBeNull();
    expect(chg34Testid($html, 'tone-operating_margin'))->toBeNull();
});

test('詳細: 値がnullの指標のtone要素は出さず、「取得不可」のフォールバックは変わらない', function () {
    // Arrange
    $holding = chg34Holding('jp', '食品', ['roe' => null, 'equity_ratio' => null, 'operating_margin' => null, 'revenue_growth' => null]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    foreach (['roe', 'equity_ratio', 'operating_margin', 'revenue_growth'] as $key) {
        expect(chg34Testid($html, 'tone-'.$key))->toBeNull();
    }
    expect($html)->toContain('<dd>取得不可</dd>');
});

test('詳細: 基準表の設定を差し替えると表示の判定・基準欄に反映される', function () {
    // Arrange
    config(['valuation_benchmarks' => [
        'jp' => [
            'as_of' => '2030-01',
            'source' => 'test-source',
            'per' => ['食品' => ['value' => 10.0, 'confidence' => 'high']],
            'pbr' => ['食品' => ['value' => 1.0, 'confidence' => 'high']],
        ],
    ]]);
    $holding = chg34Holding('jp', '食品', ['per' => 10.0, 'pbr' => 1.0]);

    // Act
    $html = chg34DetailHtml($holding);

    // Assert
    expect(chg34Testid($html, 'per-verdict')['text'])->toContain('並み');
    $benchmark = chg34Testid($html, 'per-benchmark');
    expect($benchmark['text'])->toContain('業種基準 10.0')->toContain('基準日 2030-01')->toContain('出典 test-source');
});
