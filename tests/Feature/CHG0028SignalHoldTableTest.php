<?php

namespace Tests\Feature;

use App\Actions\Portfolio\ClassifyHoldingsAction;
use App\Livewire\Signal\SignalList;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0028: 売買シグナル画面へのキープ（hold）表の掲載 — Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/product/use-cases.md UC-013 業務ルール
| 「売買シグナル画面へのキープ表の掲載」（Gate2承認済み）。
|
| Contract this Red phase proposes:
|   - SignalList の画面末尾に見出し「キープ（ホールド）」の4つ目のテーブルを表示する
|     （ClassifyHoldingsAction の hold バケツを再利用。core_accumulation は出さない）
|   - 列: 銘柄 → 評価額 → 含み損益率 → 要観察バッジ → ヘルスライン → セクター
|     （CHG-0046でヘルスライン列を廃止し判定チェックリスト列を追加）
|   - 並びは SignalList::$sort に従う（既定 評価額順 / recommended は
|     hold_watch 先頭 → 含み損益率の低い順）
|   - ClassifyHoldingsAction の各行に sector_name（未分類は '未分類'）を追加
|
| 未認証リダイレクト（/signals → /login）は tests/Feature/SignalListTest.php に
| 既存のため重複させない。
|
| Fixtures use a unique `chg28Test` prefix to avoid cross-file function
| redeclaration errors.
*/

const CHG28_HEADING = 'キープ（ホールド）';

function chg28TestSnapshot(): Snapshot
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

function chg28TestHolding(Snapshot $snapshot, string $code, string $name, array $snapshotAttributes = [], array $holdingAttributes = []): HoldingSnapshot
{
    $holding = Holding::create(array_merge([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => $name,
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $holdingAttributes));

    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => 1050,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 5.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $snapshotAttributes));
}

/** 健全な指標（hold_watch (a)(b) 非該当）。 */
function chg28TestHealthy(Holding $holding): void
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
    FundamentalIndicator::updateOrCreate(['holding_id' => $holding->id], [
        'per' => 15.0, 'pbr' => 1.5, 'roe' => 15.2, 'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3, 'equity_ratio' => 58.0, 'operating_margin' => 18.3,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ]);
}

/**
 * キープ3銘柄。評価額順: 大型(1,050,000) > 中型(510,000) > 要観察(84,000)。
 * おすすめ順: 要観察(hold_watch) → 中型(+2.0%) → 大型(+5.0%)。
 */
function chg28TestSeedHold(Snapshot $snapshot): void
{
    $large = chg28TestHolding($snapshot, '5001', 'キープ大型', [
        'quantity' => 1000, 'current_price' => 1050, 'unrealized_gain_rate' => 5.0,
    ]);
    chg28TestHealthy($large->holding);

    $mid = chg28TestHolding($snapshot, '5002', 'キープ中型', [
        'quantity' => 500, 'current_price' => 1020, 'unrealized_gain_rate' => 2.0,
    ]);
    chg28TestHealthy($mid->holding);

    // -16.0%: 整理検討ライン未満だが hold_watch バッファ(-15.0%以下)に該当
    $watch = chg28TestHolding($snapshot, '5003', 'キープ要観察', [
        'quantity' => 100, 'current_price' => 840, 'unrealized_gain_amount' => -16000, 'unrealized_gain_rate' => -16.0,
    ]);
    chg28TestHealthy($watch->holding);
}

/** 整理検討・利確検討・買い増し候補・ETF・投信・つみたてNISAのみ の銘柄（いずれもキープ表に出ない）。 */
function chg28TestSeedNonHold(Snapshot $snapshot): void
{
    $loss = chg28TestHolding($snapshot, '6001', '除外整理銘柄', [
        'quantity' => 100, 'average_cost' => 1000, 'current_price' => 600,
        'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
    ]);
    TechnicalIndicator::create([
        'holding_id' => $loss->holding_id,
        'rsi' => 50.0, 'macd' => -5.0, 'macd_signal' => -2.0,
        'ma20' => 1000.0, 'ma75' => 750.0, 'bb_upper' => 1200.0, 'bb_lower' => 800.0,
        'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0,
        'week52_high' => 1000.0, 'week52_low' => 580.0,
        'relative_strength_vs_market' => -12.0, 'relative_strength_vs_sector' => 0.0,
        'computed_at' => now(),
    ]);

    $take = chg28TestHolding($snapshot, '6002', '除外利確銘柄', ['current_price' => 1300, 'unrealized_gain_rate' => 30.0]);
    foreach (['rsi_reversal', 'macd_dead_cross'] as $type) {
        Signal::create(['holding_snapshot_id' => $take->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }

    $buy = chg28TestHolding($snapshot, '6003', '除外買増銘柄', [
        'average_cost' => 900, 'current_price' => 800, 'unrealized_gain_rate' => -8.5,
    ]);
    FundamentalIndicator::updateOrCreate(['holding_id' => $buy->holding_id], [
        'per' => 15.0, 'pbr' => 1.5, 'roe' => 15.2, 'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3, 'equity_ratio' => 58.0, 'operating_margin' => 18.3,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ]);
    foreach (['rsi_oversold_rebound', 'volume_spike_rebound'] as $type) {
        BuySignal::create(['holding_snapshot_id' => $buy->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }

    chg28TestHolding($snapshot, '6004', '除外ETF銘柄', [], ['instrument_type' => 'etf']);
    chg28TestHolding($snapshot, '6005', '除外投信銘柄', [], ['instrument_type' => 'mutual_fund']);

    $tsumitate = chg28TestHolding($snapshot, '6006', '除外つみたて銘柄');
    HoldingSnapshotAccount::create([
        'holding_snapshot_id' => $tsumitate->id,
        'account_type' => 'nisa_tsumitate',
        'quantity' => 100,
        'average_cost' => 1000.00,
    ]);
}

/** 描画済みHTMLから「キープ（ホールド）」見出し以降のテキストを返す。 */
function chg28TestHoldSection(string $html): string
{
    $pos = strpos($html, CHG28_HEADING);
    expect($pos)->not->toBeFalse('キープ（ホールド）の見出しが表示されていません');

    return preg_replace('/\s+/', ' ', strip_tags(substr($html, (int) $pos)));
}

/** セクションテキスト中の、指定銘柄名から次の銘柄名（または末尾）までの1行分のテキスト。 */
function chg28TestRowText(string $section, string $name, array $allNames): string
{
    $start = strpos($section, $name);
    expect($start)->not->toBeFalse("{$name} がキープ表に表示されていません");

    $end = strlen($section);
    foreach ($allNames as $other) {
        $p = strpos($section, $other, $start + strlen($name));
        if ($p !== false && $p < $end) {
            $end = $p;
        }
    }

    return substr($section, (int) $start, $end - (int) $start);
}

describe('CHG-0028: 売買シグナル画面のキープ表（Livewire）', function () {
    test('hold バケツの銘柄がキープ表に銘柄名・評価額・含み損益率つきで表示される', function () {
        $user = User::factory()->create();
        chg28TestSeedHold(chg28TestSnapshot());

        $html = Livewire::actingAs($user)->test(SignalList::class)->html();
        $section = chg28TestHoldSection($html);

        $names = ['キープ大型', 'キープ中型', 'キープ要観察'];
        $large = chg28TestRowText($section, 'キープ大型', $names);
        expect($large)->toContain('1,050,000円')->toContain('+5.0%');
        $watch = chg28TestRowText($section, 'キープ要観察', $names);
        expect($watch)->toContain('84,000円')->toContain('-16.0%');
    });

    test('整理検討・利確検討・買い増し候補およびETF・投信・つみたてNISAのみの銘柄はキープ表に出ない', function () {
        $user = User::factory()->create();
        $snapshot = chg28TestSnapshot();
        chg28TestSeedHold($snapshot);
        chg28TestSeedNonHold($snapshot);

        $html = Livewire::actingAs($user)->test(SignalList::class)->html();
        $section = chg28TestHoldSection($html);

        expect($section)->toContain('キープ大型');
        foreach (['除外整理銘柄', '除外利確銘柄', '除外買増銘柄', '除外ETF銘柄', '除外投信銘柄', '除外つみたて銘柄'] as $name) {
            expect($section)->not->toContain($name);
        }
    });

    test('hold_watch の銘柄にだけ「要観察」バッジが表示される', function () {
        $user = User::factory()->create();
        chg28TestSeedHold(chg28TestSnapshot());

        $section = chg28TestHoldSection(Livewire::actingAs($user)->test(SignalList::class)->html());

        $names = ['キープ大型', 'キープ中型', 'キープ要観察'];
        expect(chg28TestRowText($section, 'キープ要観察', $names))->toContain('要観察');
        expect(chg28TestRowText($section, 'キープ大型', $names))->not->toContain('要観察');
        expect(chg28TestRowText($section, 'キープ中型', $names))->not->toContain('要観察');
    });

    // CHG-0046: ヘルスライン列は判定チェックリスト列に置き換えて廃止
    // （tests/Feature/CHG0046HoldTableRichColumnsTest.php で担保）。

    test('既定の並び順は評価額の大きい順になる', function () {
        $user = User::factory()->create();
        chg28TestSeedHold(chg28TestSnapshot());

        $section = chg28TestHoldSection(Livewire::actingAs($user)->test(SignalList::class)->html());

        expect(strpos($section, 'キープ大型'))->toBeLessThan(strpos($section, 'キープ中型'))
            ->and(strpos($section, 'キープ中型'))->toBeLessThan(strpos($section, 'キープ要観察'));
    });

    test('おすすめ順では要観察が先頭になり、続いて含み損益率の低い順に並ぶ', function () {
        $user = User::factory()->create();
        chg28TestSeedHold(chg28TestSnapshot());

        $html = Livewire::actingAs($user)->test(SignalList::class)->call('setSort', 'recommended')->html();
        $section = chg28TestHoldSection($html);

        expect(strpos($section, 'キープ要観察'))->toBeLessThan(strpos($section, 'キープ中型'))
            ->and(strpos($section, 'キープ中型'))->toBeLessThan(strpos($section, 'キープ大型'));
    });

    test('URLに不正な並び順が指定されてもキープ表は評価額順で描画される', function () {
        $user = User::factory()->create();
        chg28TestSeedHold(chg28TestSnapshot());

        $html = Livewire::actingAs($user)->withQueryParams(['sort' => 'bogus'])->test(SignalList::class)->html();
        $section = chg28TestHoldSection($html);

        expect(strpos($section, 'キープ大型'))->toBeLessThan(strpos($section, 'キープ中型'))
            ->and(strpos($section, 'キープ中型'))->toBeLessThan(strpos($section, 'キープ要観察'));
    });

    test('キープ表にセクター名が表示され、未分類の銘柄は「未分類」と表示される', function () {
        $user = User::factory()->create();
        $snapshot = chg28TestSnapshot();
        $sector = SectorClassification::create(['code' => null, 'name' => '輸送用機器']);
        $classified = chg28TestHolding($snapshot, '5101', 'セクター有銘柄', ['quantity' => 1000], ['sector_classification_id' => $sector->id]);
        chg28TestHealthy($classified->holding);
        $unclassified = chg28TestHolding($snapshot, '5102', 'セクター無銘柄', ['quantity' => 10]);
        chg28TestHealthy($unclassified->holding);

        $section = chg28TestHoldSection(Livewire::actingAs($user)->test(SignalList::class)->html());

        $names = ['セクター有銘柄', 'セクター無銘柄'];
        expect(chg28TestRowText($section, 'セクター有銘柄', $names))->toContain('輸送用機器')
            ->and(chg28TestRowText($section, 'セクター無銘柄', $names))->toContain('未分類');
    });

    test('キープ銘柄が0件でも画面は描画され、キープ表は空状態を示し、他の3テーブルは従来どおり表示される', function () {
        $user = User::factory()->create();
        $snapshot = chg28TestSnapshot();
        chg28TestSeedNonHold($snapshot);

        $component = Livewire::actingAs($user)->test(SignalList::class);
        $component->assertOk()
            ->assertSee('除外整理銘柄')
            ->assertSee('除外利確銘柄')
            ->assertSee('除外買増銘柄');

        $section = chg28TestHoldSection($component->html());
        expect($section)->toContain('ありません');
    });

    test('認証済みユーザーが /signals を開くとキープ表の見出しが表示される', function () {
        $user = User::factory()->create();
        chg28TestSeedHold(chg28TestSnapshot());

        $this->actingAs($user)->get('/signals')
            ->assertOk()
            ->assertSee(CHG28_HEADING)
            ->assertSee('キープ大型');
    });
});

describe('CHG-0028: ClassifyHoldingsAction の sector_name 追加', function () {
    test('hold 行に sector_name が含まれ、未分類は「未分類」になる', function () {
        $snapshot = chg28TestSnapshot();
        $sector = SectorClassification::create(['code' => null, 'name' => '輸送用機器']);
        $a = chg28TestHolding($snapshot, '5101', 'セクター有銘柄', [], ['sector_classification_id' => $sector->id]);
        chg28TestHealthy($a->holding);
        $b = chg28TestHolding($snapshot, '5102', 'セクター無銘柄');
        chg28TestHealthy($b->holding);

        $result = app(ClassifyHoldingsAction::class)->execute();
        $hold = collect($result['buckets'])->firstWhere('bucket', 'hold')['holdings'];
        $byCode = collect($hold)->keyBy('symbol_code');

        expect($byCode['5101'])->toHaveKey('sector_name')
            ->and($byCode['5101']['sector_name'])->toBe('輸送用機器')
            ->and($byCode['5102']['sector_name'])->toBe('未分類');
    });

    test('既存の buckets 構造と行データの既存キーは変わらない', function () {
        $snapshot = chg28TestSnapshot();
        chg28TestSeedHold($snapshot);

        $result = app(ClassifyHoldingsAction::class)->execute();

        expect(array_keys($result))->toBe([
            'classified_at', 'group_summary', 'hold_breakdown', 'buckets', 'sector_overweight_summary', 'new_entry_reference',
        ]);
        expect(array_column($result['buckets'], 'bucket'))
            ->toBe(['core_accumulation', 'loss_review', 'take_profit', 'add_on', 'hold', 'new_entry']);

        $row = collect($result['buckets'])->firstWhere('bucket', 'hold')['holdings'][0];
        foreach ([
            'symbol_code', 'symbol_name', 'market', 'instrument_type', 'market_value', 'unrealized_gain_rate',
            'bucket_reason', 'also_matched', 'overweight_sector', 'hold_watch', 'health_line',
        ] as $key) {
            expect($row)->toHaveKey($key);
        }
        expect($row)->toHaveKey('sector_name');
    });
});
