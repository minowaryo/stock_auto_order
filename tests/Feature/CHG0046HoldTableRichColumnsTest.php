<?php

namespace Tests\Feature;

use App\Actions\Portfolio\ClassifyHoldingsAction;
use App\Actions\Portfolio\ShowHoldListAction;
use App\Livewire\Signal\SignalList;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;
use Mockery;

/*
|--------------------------------------------------------------------------
| CHG-0046: キープ表の列拡充と判定チェックリストの両方向色分け — Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/product/use-cases.md UC-013 業務ルール
| 「キープ表の列拡充と判定チェックリストの両方向色分け（CHG-0046）」（Gate2承認済み）。
|
| Contract this Red phase proposes:
|   - 固定列: 銘柄（/holdings/{id} リンク＋サマリ「利確寄り n」「押し目寄り m」「財務 x/4」）
|     → 評価額 → 含み損益率 → 要観察（バッジ＋理由）→ 財務健全性 → セクター
|   - 判定チェックリスト: テクニカル10項目＋財務4項目をチップ1項目=1列で表示
|     利確寄り=amber系 / 押し目寄り=green系 のチップ
|   - ヘルスライン列は廃止
|   - ClassifyHoldingsAction の hold 行のキー集合は変えない（行の拡充は ShowHoldListAction 側）
|
| 並び順・hold バケツ所属・空状態・セクター名は CHG0028SignalHoldTableTest.php で担保済み。
| 未認証リダイレクトは SignalListTest.php に既存。
|
| Fixtures use a unique `chg46Test` prefix to avoid cross-file function
| redeclaration errors.
*/

const CHG46_HEADING = 'キープ（ホールド）';

function chg46TestSnapshot(): Snapshot
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

function chg46TestHolding(Snapshot $snapshot, string $code, string $name, array $snapshotAttributes = [], array $holdingAttributes = []): HoldingSnapshot
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

/**
 * 既定値（現在値1050）での判定:
 *   利確寄り met = 52週高値からの下落率 -12.5%（1件）
 *   押し目寄り met = MACD-シグナル線 +0.50（1件）、PEG 1.2 は押し目あと一歩
 *   財務4項目すべて達成
 */
function chg46TestIndicators(Holding $holding, array $technical = [], ?array $fundamental = []): void
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

    if ($fundamental === null) {
        return;
    }

    FundamentalIndicator::updateOrCreate(['holding_id' => $holding->id], array_merge([
        'per' => 15.0, 'pbr' => 1.5, 'roe' => 15.2, 'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3, 'equity_ratio' => 58.0, 'operating_margin' => 18.3,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ], $fundamental));
}

/** 「キープ（ホールド）」見出し以降の生HTML。 */
function chg46TestHoldHtml(string $html): string
{
    $pos = strpos($html, CHG46_HEADING);
    expect($pos)->not->toBeFalse('キープ（ホールド）の見出しが表示されていません');

    return substr($html, (int) $pos);
}

function chg46TestText(string $html): string
{
    return preg_replace('/\s+/', ' ', strip_tags($html));
}

/** 生HTMLから、銘柄名を含む <tr>…</tr> を1行分取り出す。 */
function chg46TestRowHtml(string $holdHtml, string $name): string
{
    $namePos = strpos($holdHtml, $name);
    expect($namePos)->not->toBeFalse("{$name} がキープ表に表示されていません");

    $start = strrpos(substr($holdHtml, 0, (int) $namePos), '<tr');
    $end = strpos($holdHtml, '</tr>', (int) $namePos);

    return substr($holdHtml, (int) $start, (int) $end - (int) $start);
}

function chg46TestRender(?string $sort = null): string
{
    $component = Livewire::actingAs(User::factory()->create())->test(SignalList::class);
    if ($sort !== null) {
        $component->call('setSort', $sort);
    }

    return chg46TestHoldHtml($component->html());
}

describe('CHG-0046: キープ表の列拡充', function () {
    test('キープ表のヘッダーにテクニカル10項目と財務4項目の列見出しが表示され、ヘルスライン列はなくなる', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $text = chg46TestText(chg46TestRender());

        foreach ([
            '要観察', '財務健全性', 'セクター',
            '判定チェックリスト（財務）',
            '含み益率', 'RSI', '52週高値からの下落率', '52週安値からの距離', 'MA20乖離率',
            'MACD-シグナル線', '相対力(対セクター)', 'PEGレシオ', 'PER', 'PBR',
            'ROE', '自己資本比率', '成長率', '営業利益率',
        ] as $label) {
            expect($text)->toContain($label);
        }
        expect($text)->not->toContain('ヘルスライン');
    });

    test('各行にPER・PBR・財務指標を含む各指標の実測値と基準が表示される', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $row = chg46TestText(chg46TestRowHtml(chg46TestRender(), 'キープ銘柄'));

        foreach (['50.0', '利確≥70／押し目≤30', '-12.5%', '0.50', '1.20', '15.0', '1.50', '15.2%', '58.0%', '+12.3%', '18.3%'] as $value) {
            expect($row)->toContain($value);
        }
    });

    test('財務健全性列に他テーブルと同じ内訳サマリ文が表示される', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $row = chg46TestText(chg46TestRowHtml(chg46TestRender(), 'キープ銘柄'));

        expect($row)->toContain('ROE15.2%・自己資本比率58.0%・成長率+12.3%・営業利益率18.3%');
    });

    test('利確寄りの項目は黄、押し目寄りの項目は緑のチップで色分けされる', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $row = chg46TestRowHtml(chg46TestRender(), 'キープ銘柄');

        // 利確寄り met（52週高値からの下落率）
        expect($row)->toContain('bg-amber-100');
        // 押し目寄り met（MACD・財務）と押し目あと一歩（PEG）
        expect($row)->toContain('bg-green-100')->toContain('bg-green-50');
        // キープ表に赤チップは出さない
        expect($row)->not->toContain('bg-red-100');
    });

    test('銘柄セルに利確寄り・押し目寄り・財務の達成数サマリが表示される', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $row = chg46TestText(chg46TestRowHtml(chg46TestRender(), 'キープ銘柄'));

        expect($row)->toContain('利確寄り 1')->toContain('押し目寄り 1')->toContain('財務 4/4');
    });

    test('銘柄名が保有銘柄詳細へのリンクになる', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $row = chg46TestRowHtml(chg46TestRender(), 'キープ銘柄');

        expect($row)->toContain('href="/holdings/'.$hs->holding_id.'"');
    });

    test('要観察の銘柄には該当理由が表示される', function () {
        $snapshot = chg46TestSnapshot();

        $loss = chg46TestHolding($snapshot, '5101', '含み損要観察', [
            'current_price' => 840, 'unrealized_gain_amount' => -16000, 'unrealized_gain_rate' => -16.0,
        ]);
        chg46TestIndicators($loss->holding);

        $weakRs = chg46TestHolding($snapshot, '5102', '相対力要観察');
        chg46TestIndicators($weakRs->holding, ['relative_strength_vs_sector' => -2.0]);

        $failed = chg46TestHolding($snapshot, '5103', '財務要観察');
        chg46TestIndicators($failed->holding, [], ['roe' => 2.0]);

        $healthy = chg46TestHolding($snapshot, '5104', '健全銘柄');
        chg46TestIndicators($healthy->holding);

        $html = chg46TestRender();

        expect(chg46TestText(chg46TestRowHtml($html, '含み損要観察')))->toContain('要観察')->toContain('含み損-15%以下');
        expect(chg46TestText(chg46TestRowHtml($html, '相対力要観察')))->toContain('相対力マイナス');
        expect(chg46TestText(chg46TestRowHtml($html, '財務要観察')))->toContain('財務健全性 基準割れ');
        expect(chg46TestText(chg46TestRowHtml($html, '健全銘柄')))
            ->not->toContain('含み損-15%以下')
            ->not->toContain('相対力マイナス')
            ->not->toContain('基準割れ');
    });

    test('財務指標が未取得の銘柄は「財務指標 取得不可」と表示され、財務チップは「—」になる', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', '未取得銘柄');
        chg46TestIndicators($hs->holding, [], null);

        $row = chg46TestText(chg46TestRowHtml(chg46TestRender(), '未取得銘柄'));

        expect($row)->toContain('財務指標 取得不可')->toContain('—')->toContain('財務 0/4');
    });

    test('米国株は円換算前の現在値で価格乖離を計算する', function () {
        // current_price は円換算済み（15,750円 = 105ドル × 150）、指標はドル建て。
        $hs = chg46TestHolding(chg46TestSnapshot(), 'USX', '米国キープ', [
            'current_price' => 15750, 'fx_rate_used' => 150.0, 'quantity' => 10, 'unrealized_gain_rate' => 3.0,
        ], ['market' => 'us']);
        chg46TestIndicators($hs->holding, ['ma20' => 100.0, 'week52_high' => 120.0, 'week52_low' => 80.0]);

        $row = chg46TestText(chg46TestRowHtml(chg46TestRender(), '米国キープ'));

        // MA20乖離率 +5.0%（円のまま比較すると +15650.0% になる）
        expect($row)->toContain('+5.0%')->not->toContain('+15650.0%');
    });

    test('おすすめ順に切り替えても列構成と色分けは変わらない', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $row = chg46TestRowHtml(chg46TestRender('recommended'), 'キープ銘柄');

        expect($row)->toContain('bg-amber-100')->toContain('bg-green-100');
        expect(chg46TestText($row))->toContain('利確寄り 1')->toContain('15.2%');
    });
});

describe('CHG-0046 /review 指摘: 利確ラインと行の欠落', function () {
    test('財務健全性が合格でシグナル0件の銘柄は、利確検討と同じ高水準モード+150%で含み益率を判定する', function () {
        // 既定の財務指標は FundamentalHealthEvaluator で passed → TakeProfitThresholdEvaluator は high_water_mark
        $hs = chg46TestHolding(chg46TestSnapshot(), '6098', '高水準銘柄', [
            'current_price' => 1980, 'unrealized_gain_amount' => 98000, 'unrealized_gain_rate' => 98.0,
        ]);
        chg46TestIndicators($hs->holding);

        $gainChip = collect(app(ShowHoldListAction::class)->execute())->firstWhere('symbol_code', '6098')['criteria']['technical'][0];
        expect([$gainChip['label'], $gainChip['status'], $gainChip['tone'], $gainChip['threshold_label']])
            ->toBe(['含み益率', 'unmet', null, '利確≥+150%']);

        $row = chg46TestText(chg46TestRowHtml(chg46TestRender(), '高水準銘柄'));
        expect($row)->toContain('利確≥+150%')->not->toContain('利確≥+20%');
    });

    test('財務健全性が基準割れの銘柄は、通常モード+20%で含み益率を判定する', function () {
        // 課税口座で+20%超なら利確検討に入るため、キープに残るのはNISA成長投資枠のみの保有
        // （実データのAAPLと同じ状況）。
        $hs = chg46TestHolding(chg46TestSnapshot(), '6099', '通常銘柄', [
            'current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0,
        ]);
        HoldingSnapshotAccount::create([
            'holding_snapshot_id' => $hs->id,
            'account_type' => 'nisa_growth',
            'quantity' => 100,
            'average_cost' => 1000.00,
        ]);
        chg46TestIndicators($hs->holding, [], ['roe' => 2.0]);

        $gainChip = collect(app(ShowHoldListAction::class)->execute())->firstWhere('symbol_code', '6099')['criteria']['technical'][0];
        expect([$gainChip['status'], $gainChip['tone'], $gainChip['threshold_label']])
            ->toBe(['met', 'warning', '利確≥+20%']);
    });

    test('分類結果の行が最新スナップショットに見つからなくても、画面はエラーにならず指標を「—」で表示する', function () {
        // 分類と行の拡充の間に取り込みが完了した状況を、分類Actionの戻り値で再現する。
        $classify = Mockery::mock(ClassifyHoldingsAction::class);
        $classify->shouldReceive('execute')->andReturn(['buckets' => [[
            'bucket' => 'hold',
            'group' => 'hold',
            'holdings' => [[
                'symbol_code' => '9999', 'symbol_name' => '消えた銘柄', 'market' => 'jp', 'instrument_type' => 'stock',
                'market_value' => 100000.0, 'unrealized_gain_rate' => 1.0, 'bucket_reason' => 'x', 'also_matched' => [],
                'overweight_sector' => false, 'hold_watch' => false, 'health_line' => 'x', 'sector_name' => '未分類',
            ]],
        ]]]);
        app()->instance(ClassifyHoldingsAction::class, $classify);

        $component = Livewire::actingAs(User::factory()->create())->test(SignalList::class);

        $component->assertOk();
        $row = chg46TestText(chg46TestRowHtml(chg46TestHoldHtml($component->html()), '消えた銘柄'));
        expect($row)->toContain('—')->toContain('財務指標 取得不可')->toContain('財務 0/4');
        expect(chg46TestRowHtml(chg46TestHoldHtml($component->html()), '消えた銘柄'))->not->toContain('href="/holdings/"');
    });
});

describe('CHG-0046: 分類Actionの出力は変えない', function () {
    test('ClassifyHoldingsAction の hold 行のキー集合は従来どおり', function () {
        $hs = chg46TestHolding(chg46TestSnapshot(), '5001', 'キープ銘柄');
        chg46TestIndicators($hs->holding);

        $hold = collect(app(ClassifyHoldingsAction::class)->execute()['buckets'])->firstWhere('bucket', 'hold')['holdings'];

        expect(array_keys($hold[0]))->toBe([
            'symbol_code', 'symbol_name', 'market', 'instrument_type', 'market_value', 'unrealized_gain_rate',
            'bucket_reason', 'also_matched', 'overweight_sector', 'hold_watch', 'health_line', 'sector_name',
        ]);
    });
});
