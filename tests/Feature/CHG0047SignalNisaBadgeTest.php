<?php

namespace Tests\Feature;

use App\Actions\Portfolio\ShowHoldListAction;
use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Livewire\Signal\SignalList;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0047: 売買シグナル画面のNISA保有区分バッジ — Red phase
|--------------------------------------------------------------------------
|
| Source of truth: docs/product/use-cases.md UC-004 業務ルール
| 「NISA保有区分のバッジ（CHG-0047）」（Gate2承認済み）。
|
| Contract this Red phase proposes:
|   - 4テーブル（買い増し候補・利確検討・整理検討・キープ）の銘柄セル（先頭の<td>）に
|     NISAのみ →「NISAのみ」、NISAと課税口座の混在 →「NISA一部」を表示。
|     課税口座のみ・内訳なしは表示しない。
|   - 各Actionの行に nisa_holding（'nisa_only' / 'nisa_partial' / null）を追加。
|     /api/signals・/api/buy-signals の JSON にも同じ項目が含まれる。
|   - 抽出条件は変えない（利確検討はNISAのみの銘柄を従来どおり対象外）。
|
| 未認証リダイレクトは SignalListTest.php に既存。
| Fixtures use a unique `chg47Test` prefix.
*/

function chg47TestSnapshot(): Snapshot
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

function chg47TestHolding(Snapshot $snapshot, string $code, string $name, array $snapshotAttributes = []): HoldingSnapshot
{
    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => $name,
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ]);

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

/** @param  array<int, string>  $accountTypes */
function chg47TestAccounts(HoldingSnapshot $hs, array $accountTypes): void
{
    foreach ($accountTypes as $type) {
        HoldingSnapshotAccount::create([
            'holding_snapshot_id' => $hs->id,
            'account_type' => $type,
            'quantity' => 50,
            'average_cost' => 1000.00,
        ]);
    }
}

function chg47TestHealthyFundamentals(Holding $holding): void
{
    FundamentalIndicator::updateOrCreate(['holding_id' => $holding->id], [
        'per' => 15.0, 'pbr' => 1.5, 'roe' => 15.2, 'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3, 'equity_ratio' => 58.0, 'operating_margin' => 18.3,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ]);
}

function chg47TestNeutralTechnical(Holding $holding): void
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
}

/** 4テーブルそれぞれにNISA区分の異なる銘柄を置く。 */
function chg47TestSeed(): void
{
    $snapshot = chg47TestSnapshot();

    // 利確検討: 課税口座あり・+30%（財務未取得 → 通常モード+20%）
    $take = chg47TestHolding($snapshot, '7001', '利確銘柄A', ['current_price' => 1300, 'unrealized_gain_amount' => 30000, 'unrealized_gain_rate' => 30.0]);
    chg47TestAccounts($take, ['specific', 'nisa_growth']);

    // 利確検討の対象外（NISAのみ）→ キープに入る（実データのAAPLと同じ状況）
    $nisaOnlyGain = chg47TestHolding($snapshot, '7002', '含み益銘柄B', ['current_price' => 1300, 'unrealized_gain_amount' => 30000, 'unrealized_gain_rate' => 30.0]);
    chg47TestAccounts($nisaOnlyGain, ['nisa_growth']);
    chg47TestNeutralTechnical($nisaOnlyGain->holding);

    // 買い増し候補: 押し目買いシグナルあり・財務健全
    $buy = chg47TestHolding($snapshot, '7003', '買増銘柄C', ['average_cost' => 900, 'current_price' => 800, 'unrealized_gain_rate' => -8.5]);
    chg47TestHealthyFundamentals($buy->holding);
    foreach (['rsi_oversold_rebound', 'volume_spike_rebound'] as $type) {
        BuySignal::create(['holding_snapshot_id' => $buy->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }
    chg47TestAccounts($buy, ['nisa_growth']);

    // 整理検討（含み損）: -40%
    $loss = chg47TestHolding($snapshot, '7004', '整理銘柄D', [
        'current_price' => 600, 'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
    ]);
    chg47TestAccounts($loss, ['specific', 'nisa_growth']);

    // キープ: NISA一部（特定＋つみたて。つみたてのみではないのでコア積立にはならない）
    $holdPartial = chg47TestHolding($snapshot, '7005', 'キープ銘柄E');
    chg47TestAccounts($holdPartial, ['specific', 'nisa_tsumitate']);
    chg47TestNeutralTechnical($holdPartial->holding);

    // キープ: 課税口座のみ・内訳なし（バッジなし）
    $taxable = chg47TestHolding($snapshot, '7006', 'キープ課税F');
    chg47TestAccounts($taxable, ['specific']);
    chg47TestNeutralTechnical($taxable->holding);

    $legacy = chg47TestHolding($snapshot, '7007', 'キープ旧G');
    chg47TestNeutralTechnical($legacy->holding);
}

/** 見出し（<h2>）単位でテーブルのHTMLを切り出す。 */
function chg47TestSection(string $html, string $heading): string
{
    foreach (explode('<h2', $html) as $section) {
        if (str_contains(substr($section, 0, 200), $heading)) {
            return $section;
        }
    }

    throw new \RuntimeException("{$heading} の見出しが見つかりません");
}

/** 銘柄名を含む行の先頭<td>（銘柄セル）のテキスト。 */
function chg47TestNameCell(string $section, string $name): string
{
    $namePos = strpos($section, $name);
    expect($namePos)->not->toBeFalse("{$name} が表示されていません");

    $start = strrpos(substr($section, 0, (int) $namePos), '<td');
    $end = strpos($section, '</td>', (int) $namePos);

    return preg_replace('/\s+/', ' ', strip_tags(substr($section, (int) $start, (int) $end - (int) $start)));
}

function chg47TestRender(): string
{
    return Livewire::actingAs(User::factory()->create())->test(SignalList::class)->html();
}

describe('CHG-0047: 銘柄セルのNISAバッジ（画面）', function () {
    test('買い増し候補の銘柄セルに NISAのみ が表示される', function () {
        chg47TestSeed();

        $cell = chg47TestNameCell(chg47TestSection(chg47TestRender(), '買い増し候補'), '買増銘柄C');

        expect($cell)->toContain('NISAのみ')->not->toContain('NISA一部');
    });

    test('利確検討の銘柄セルに NISA一部 が表示される', function () {
        chg47TestSeed();

        $cell = chg47TestNameCell(chg47TestSection(chg47TestRender(), '利確検討'), '利確銘柄A');

        expect($cell)->toContain('NISA一部')->not->toContain('NISAのみ');
    });

    test('整理検討（含み損）の銘柄セルに NISA一部 が表示される', function () {
        chg47TestSeed();

        $cell = chg47TestNameCell(chg47TestSection(chg47TestRender(), '整理検討'), '整理銘柄D');

        expect($cell)->toContain('NISA一部');
    });

    test('キープ表の銘柄セルに NISAのみ・NISA一部 が表示され、課税口座のみと内訳なしには表示されない', function () {
        chg47TestSeed();

        $section = chg47TestSection(chg47TestRender(), 'キープ（ホールド）');

        expect(chg47TestNameCell($section, '含み益銘柄B'))->toContain('NISAのみ');
        expect(chg47TestNameCell($section, 'キープ銘柄E'))->toContain('NISA一部');
        expect(chg47TestNameCell($section, 'キープ課税F'))->not->toContain('NISA');
        expect(chg47TestNameCell($section, 'キープ旧G'))->not->toContain('NISA');
    });

    test('NISAのみで含み益+30%の銘柄は従来どおり利確検討に出ず、キープ表に NISAのみ として出る', function () {
        chg47TestSeed();

        $html = chg47TestRender();

        expect(chg47TestSection($html, '利確検討'))->not->toContain('含み益銘柄B');
        expect(chg47TestNameCell(chg47TestSection($html, 'キープ（ホールド）'), '含み益銘柄B'))->toContain('NISAのみ');
    });
});

describe('CHG-0047: 行データの nisa_holding', function () {
    test('4つのActionの行に nisa_holding が入る', function () {
        chg47TestSeed();

        $byCode = fn (array $rows) => collect($rows)->keyBy('symbol_code');

        expect($byCode(app(ShowSignalListAction::class)->execute())['7001']['nisa_holding'])->toBe('nisa_partial');
        expect($byCode(app(ShowBuySignalListAction::class)->execute())['7003']['nisa_holding'])->toBe('nisa_only');
        expect($byCode(app(ShowLossReviewListAction::class)->execute())['7004']['nisa_holding'])->toBe('nisa_partial');

        $hold = $byCode(app(ShowHoldListAction::class)->execute());
        expect($hold['7002']['nisa_holding'])->toBe('nisa_only')
            ->and($hold['7005']['nisa_holding'])->toBe('nisa_partial')
            ->and($hold['7006']['nisa_holding'])->toBeNull()
            ->and($hold['7007']['nisa_holding'])->toBeNull();
    });

    test('利確検討・買い増し候補の JSON API にも nisa_holding が含まれる', function () {
        $user = User::factory()->create();
        chg47TestSeed();

        $signals = collect($this->actingAs($user)->getJson('/api/signals')->assertOk()->json('data'))->keyBy('symbol_code');
        $buySignals = collect($this->actingAs($user)->getJson('/api/buy-signals')->assertOk()->json('data'))->keyBy('symbol_code');

        expect($signals['7001'])->toHaveKey('nisa_holding')
            ->and($signals['7001']['nisa_holding'])->toBe('nisa_partial');
        expect($buySignals['7003']['nisa_holding'])->toBe('nisa_only');
    });
});
