<?php

namespace Tests\Feature;

use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Livewire\Signal\SignalList;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0027: 売買シグナル画面 評価額ソート・整理検討の評価額列/列順統一 — Red phase
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-004 / UC-010 / UC-011（CHG-0027 で改訂）
|   - 本人指示（2026-10-01）: 整理検討にも評価額を出す／利確・買い増しと
|     列の並びを統一する／既定の並びを評価額順に変更し、従来の
|     「おすすめ順」（財務健全性など）も選べるようにする
|
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
|   - ShowSignalListAction / ShowBuySignalListAction / ShowLossReviewListAction
|     の execute() が任意の並び順引数 `string $sort` を受け取る。
|       'recommended'  = 従来の並び（既定値。JSON API・UC-013 の
|                        ClassifyHoldingsAction は引数なしで呼ぶため無変更）
|       'market_value' = 評価額の大きい順（同額は従来の並びで決着）
|   - ShowLossReviewListAction の各行に market_value（保有数量 × 現在値）を追加
|   - SignalList（Livewire）は public string $sort（既定 'market_value'）と
|     setSort(string $sort) を持つ。'market_value'/'recommended' 以外は無視する
|   - 整理検討テーブルの列順は 銘柄 → 評価額 → 含み損率 → 損失の実額 → …
|     （利確検討・買い増し候補の 銘柄 → 評価額 → 含み益率 と位置を揃える）
|
| Fixtures are a fresh copy with a unique `chg27Test` prefix (same convention
| as `signalListTest*` / `ucFrom011Test*`) to avoid cross-file function
| redeclaration errors.
*/

function chg27TestBatch(): Snapshot
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

function chg27TestHolding(Snapshot $snapshot, string $code, string $name, array $snapshotAttributes): HoldingSnapshot
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
        'current_price' => 1300,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $snapshotAttributes));
}

function chg27TestFundamental(Holding $holding): void
{
    FundamentalIndicator::updateOrCreate(['holding_id' => $holding->id], [
        'per' => 15.0, 'pbr' => 1.5, 'roe' => 15.2, 'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3, 'equity_ratio' => 58.0, 'operating_margin' => 18.3,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ]);
}

function chg27TestLossTechnical(Holding $holding): void
{
    TechnicalIndicator::create([
        'holding_id' => $holding->id,
        'rsi' => 50.0, 'macd' => -5.0, 'macd_signal' => -2.0,
        'ma20' => 1000.0, 'ma75' => 750.0, 'bb_upper' => 1200.0, 'bb_lower' => 800.0,
        'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0,
        'week52_high' => 1000.0, 'week52_low' => 580.0,
        'relative_strength_vs_market' => -12.0, 'relative_strength_vs_sector' => 0.0,
        'computed_at' => now(),
    ]);
}

/**
 * 利確検討: アルファ（評価額130,000・シグナル2件）とブラボー（評価額1,300,000・
 * シグナル1件）。おすすめ順ならアルファ、評価額順ならブラボーが先頭になる。
 */
function chg27TestSeedTakeProfit(?Snapshot $snapshot = null): Snapshot
{
    $snapshot ??= chg27TestBatch();
    $alpha = chg27TestHolding($snapshot, '1001', 'アルファ株式', ['quantity' => 100]);
    $bravo = chg27TestHolding($snapshot, '1002', 'ブラボー株式', ['quantity' => 1000]);
    foreach (['rsi_reversal', 'macd_dead_cross'] as $type) {
        Signal::create(['holding_snapshot_id' => $alpha->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }
    Signal::create(['holding_snapshot_id' => $bravo->id, 'signal_type' => 'rsi_reversal', 'reason_summary' => 'x']);

    return $snapshot;
}

/** 買い増し候補: アルファ（評価額80,000・シグナル2件）、ブラボー（評価額800,000・シグナル1件）。 */
function chg27TestSeedBuy(?Snapshot $snapshot = null): void
{
    $snapshot ??= chg27TestBatch();
    $alpha = chg27TestHolding($snapshot, '2001', 'アルファ素材', [
        'quantity' => 100, 'average_cost' => 900, 'current_price' => 800, 'unrealized_gain_rate' => -8.5,
    ]);
    $bravo = chg27TestHolding($snapshot, '2002', 'ブラボー素材', [
        'quantity' => 1000, 'average_cost' => 900, 'current_price' => 800, 'unrealized_gain_rate' => -8.5,
    ]);
    chg27TestFundamental($alpha->holding);
    chg27TestFundamental($bravo->holding);
    foreach (['rsi_oversold_rebound', 'volume_spike_rebound'] as $type) {
        BuySignal::create(['holding_snapshot_id' => $alpha->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }
    BuySignal::create(['holding_snapshot_id' => $bravo->id, 'signal_type' => 'rsi_oversold_rebound', 'reason_summary' => 'x']);
}

/**
 * 整理検討: アルファ（-40%・評価額60,000）とブラボー（-25%・評価額600,000）。
 * おすすめ順（含み損率の深い順）ならアルファ、評価額順ならブラボーが先頭。
 */
function chg27TestSeedLossReview(?Snapshot $snapshot = null): void
{
    $snapshot ??= chg27TestBatch();
    $alpha = chg27TestHolding($snapshot, '3001', 'アルファ電機', [
        'quantity' => 100, 'average_cost' => 1000, 'current_price' => 600,
        'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
    ]);
    $bravo = chg27TestHolding($snapshot, '3002', 'ブラボー電機', [
        'quantity' => 1000, 'average_cost' => 800, 'current_price' => 600,
        'unrealized_gain_amount' => -200000, 'unrealized_gain_rate' => -25.0,
    ]);
    chg27TestLossTechnical($alpha->holding);
    chg27TestLossTechnical($bravo->holding);
}

describe('CHG-0027: Action の並び順引数', function () {
    describe('利確検討（UC-004）', function () {
        test('並び順を指定しない場合は従来どおりシグナル数の多い順（おすすめ順）になる', function () {
            chg27TestSeedTakeProfit();

            $rows = app(ShowSignalListAction::class)->execute();

            expect(array_column($rows, 'symbol_name'))->toBe(['アルファ株式', 'ブラボー株式']);
        });

        test('market_valueを指定すると評価額の大きい順に並ぶ', function () {
            chg27TestSeedTakeProfit();

            $rows = app(ShowSignalListAction::class)->execute('market_value');

            expect(array_column($rows, 'symbol_name'))->toBe(['ブラボー株式', 'アルファ株式']);
        });

        test('評価額が同額の場合は従来のおすすめ順で決着する', function () {
            $snapshot = chg27TestBatch();
            $fewer = chg27TestHolding($snapshot, '1001', 'シグナル少', ['quantity' => 100]);
            $more = chg27TestHolding($snapshot, '1002', 'シグナル多', ['quantity' => 100]);
            Signal::create(['holding_snapshot_id' => $fewer->id, 'signal_type' => 'rsi_reversal', 'reason_summary' => 'x']);
            foreach (['rsi_reversal', 'macd_dead_cross'] as $type) {
                Signal::create(['holding_snapshot_id' => $more->id, 'signal_type' => $type, 'reason_summary' => 'x']);
            }

            $rows = app(ShowSignalListAction::class)->execute('market_value');

            expect(array_column($rows, 'symbol_name'))->toBe(['シグナル多', 'シグナル少']);
        });
    });

    describe('買い増し候補（UC-010）', function () {
        test('並び順を指定しない場合は従来どおり買い増しシグナル数の多い順になる', function () {
            chg27TestSeedBuy();

            $rows = app(ShowBuySignalListAction::class)->execute();

            expect(array_column($rows, 'symbol_name'))->toBe(['アルファ素材', 'ブラボー素材']);
        });

        test('market_valueを指定すると評価額の大きい順に並ぶ', function () {
            chg27TestSeedBuy();

            $rows = app(ShowBuySignalListAction::class)->execute('market_value');

            expect(array_column($rows, 'symbol_name'))->toBe(['ブラボー素材', 'アルファ素材']);
        });
    });

    describe('整理検討（UC-011）', function () {
        test('各行に評価額（保有数量 × 現在値）が含まれる', function () {
            chg27TestSeedLossReview();

            $rows = app(ShowLossReviewListAction::class)->execute();
            $byName = array_column($rows, null, 'symbol_name');

            expect((float) $byName['アルファ電機']['market_value'])->toBe(60000.0)
                ->and((float) $byName['ブラボー電機']['market_value'])->toBe(600000.0);
        });

        test('並び順を指定しない場合は従来どおり含み損率の深い順になる', function () {
            chg27TestSeedLossReview();

            $rows = app(ShowLossReviewListAction::class)->execute();

            expect(array_column($rows, 'symbol_name'))->toBe(['アルファ電機', 'ブラボー電機']);
        });

        test('market_valueを指定すると評価額の大きい順に並ぶ', function () {
            chg27TestSeedLossReview();

            $rows = app(ShowLossReviewListAction::class)->execute('market_value');

            expect(array_column($rows, 'symbol_name'))->toBe(['ブラボー電機', 'アルファ電機']);
        });
    });
});

describe('CHG-0027: 売買シグナル画面（Livewire）', function () {
    test('既定の並び順は評価額順で、3つのテーブルとも評価額の大きい銘柄が先に表示される', function () {
        $user = User::factory()->create();
        // 画面は直近スナップショットだけを表示するため、3テーブル分を同一スナップショットに載せる
        $snapshot = chg27TestSeedTakeProfit();
        chg27TestSeedBuy($snapshot);
        chg27TestSeedLossReview($snapshot);

        $component = Livewire::actingAs($user)->test(SignalList::class);

        $component->assertSet('sort', 'market_value')
            ->assertSeeInOrder(['ブラボー株式', 'アルファ株式'])
            ->assertSeeInOrder(['ブラボー素材', 'アルファ素材'])
            ->assertSeeInOrder(['ブラボー電機', 'アルファ電機']);
    });

    test('おすすめ順に切り替えると従来の並びになる', function () {
        $user = User::factory()->create();
        // 画面は直近スナップショットだけを表示するため、3テーブル分を同一スナップショットに載せる
        $snapshot = chg27TestSeedTakeProfit();
        chg27TestSeedBuy($snapshot);
        chg27TestSeedLossReview($snapshot);

        $component = Livewire::actingAs($user)->test(SignalList::class)->call('setSort', 'recommended');

        // call() 後の assertSeeInOrder は更新レスポンス（JSON）を見てしまうため、描画済みHTMLで順序を比べる
        $html = $component->assertSet('sort', 'recommended')->html();
        foreach ([['アルファ株式', 'ブラボー株式'], ['アルファ素材', 'ブラボー素材'], ['アルファ電機', 'ブラボー電機']] as [$first, $second]) {
            expect(strpos($html, $first))->toBeLessThan(strpos($html, $second));
        }
    });

    test('並び順の切り替えボタン（評価額順・おすすめ順）が表示される', function () {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(SignalList::class);

        $component->assertSee('評価額順')->assertSee('おすすめ順');
    });

    test('未知の並び順を指定しても現在の並び順は変わらない', function () {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(SignalList::class)->call('setSort', 'bogus');

        $component->assertSet('sort', 'market_value');
    });

    test('整理検討テーブルに評価額が桁区切りで表示され、列は 銘柄→評価額→含み損率→損失の実額 の順になる', function () {
        $user = User::factory()->create();
        chg27TestSeedLossReview();

        $component = Livewire::actingAs($user)->test(SignalList::class);

        // 600 円 × 1000 株 = 600,000円
        $component->assertSee('600,000円');

        $html = $component->html();
        $section = substr($html, (int) strpos($html, 'loss-review-header-scroll'));
        $text = preg_replace('/\s+/', ' ', strip_tags($section));
        $positions = array_map(fn (string $label) => strpos($text, $label), ['銘柄', '評価額', '含み損率', '損失の実額']);

        expect($positions)->not->toContain(false)
            ->and($positions)->toBe(collect($positions)->sort()->values()->all());
    });
});
