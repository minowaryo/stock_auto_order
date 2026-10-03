<?php

namespace Tests\Feature;

use App\Actions\Portfolio\ClassifyHoldingsAction;
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
| CHG-0032: 売買シグナル画面の供給元Action二重実行の解消 — Red phase
|--------------------------------------------------------------------------
|
| Source of truth:
|   - PLAN.md「既知の懸念（別CR候補）: 描画ごとに ClassifyHoldingsAction が
|     3 Action を二重実行する」（CHG-0027/CHG-0028）
|   - 画面の表示内容は一切変えない（ユーザー向け挙動の変更なし。性能のみ）
|
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
|   - ClassifyHoldingsAction::execute() は任意の3引数
|     (?array $lossReviewRows, ?array $takeProfitRows, ?array $addOnRows) を
|     受け取る。渡された場合はその行を供給元Actionの出力として使い、再実行
|     しない。null の引数は従来どおり自前で供給元Actionを呼ぶ（引数なしの
|     既存呼び出し・戻り値は無変更）
|   - ShowHoldListAction::execute() も同じ3引数を末尾に任意で受け取り、
|     そのまま ClassifyHoldingsAction へ渡す
|   - SignalList::render() は自分が取得済みの3テーブル分の行を渡し、
|     1回の描画で ShowSignalList / ShowBuySignalList / ShowLossReviewList の
|     execute() はそれぞれちょうど1回だけ呼ばれる
|
| Fixtures use a unique `chg32Test` prefix to avoid cross-file function
| redeclaration errors.
*/

function chg32TestSeed(): void
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

    $make = function (string $code, string $name, array $attrs) use ($snapshot): HoldingSnapshot {
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
        ], $attrs));
    };

    $healthyFundamental = fn (Holding $holding) => FundamentalIndicator::updateOrCreate(['holding_id' => $holding->id], [
        'per' => 15.0, 'pbr' => 1.5, 'roe' => 15.2, 'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3, 'equity_ratio' => 58.0, 'operating_margin' => 18.3,
        'dividend_yield' => 2.0, 'dividend_payout_ratio' => 30.0, 'eps_growth' => 10.0,
        'peg_ratio' => 1.2, 'fetched_at' => now(),
    ]);

    // hold（どの条件にも該当しない健全銘柄）
    $hold = $make('5001', 'キープ銘柄', []);
    TechnicalIndicator::create([
        'holding_id' => $hold->holding_id,
        'rsi' => 50.0, 'macd' => 1.0, 'macd_signal' => 0.5,
        'ma20' => 1000.0, 'ma75' => 950.0, 'bb_upper' => 1100.0, 'bb_lower' => 900.0,
        'volume' => 1_000_000, 'volume_ma20' => 1_000_000.0,
        'week52_high' => 1200.0, 'week52_low' => 800.0,
        'relative_strength_vs_market' => 6.0, 'relative_strength_vs_sector' => 6.0,
        'computed_at' => now(),
    ]);
    $healthyFundamental($hold->holding);

    // loss_review（-40%）
    $loss = $make('6001', '整理銘柄', [
        'current_price' => 600, 'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
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

    // take_profit（+30%・利確シグナル2種）
    $take = $make('6002', '利確銘柄', ['current_price' => 1300, 'unrealized_gain_rate' => 30.0]);
    foreach (['rsi_reversal', 'macd_dead_cross'] as $type) {
        Signal::create(['holding_snapshot_id' => $take->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }

    // add_on（-8.5%・買い増しシグナル2種）
    $buy = $make('6003', '買増銘柄', ['average_cost' => 900, 'current_price' => 800, 'unrealized_gain_rate' => -8.5]);
    $healthyFundamental($buy->holding);
    foreach (['rsi_oversold_rebound', 'volume_spike_rebound'] as $type) {
        BuySignal::create(['holding_snapshot_id' => $buy->id, 'signal_type' => $type, 'reason_summary' => 'x']);
    }
}

/** 実Actionへ委譲しつつ execute() の呼び出し回数を検証する（partialMock はコンストラクタを通らないため使わない）。 */
function chg32TestSpyOnSources(int $times): void
{
    foreach ([ShowSignalListAction::class, ShowBuySignalListAction::class, ShowLossReviewListAction::class] as $class) {
        $real = app($class);
        $mock = \Mockery::mock($class);
        $expectation = $mock->shouldReceive('execute');
        $times === 0
            ? $expectation->never()
            : $expectation->times($times)->andReturnUsing(fn (...$args) => $real->execute(...$args));
        app()->instance($class, $mock);
    }
}

describe('CHG-0032: 売買シグナル画面の供給元Action二重実行の解消', function () {
    test('売買シグナル画面を1回描画しても、利確・買い増し・整理検討の各Actionは1回ずつしか実行されない', function () {
        // Arrange
        $user = User::factory()->create();
        chg32TestSeed();
        chg32TestSpyOnSources(1);

        // Act
        $component = Livewire::actingAs($user)->test(SignalList::class);

        // Assert
        $component->assertOk();
    });

    test('並び順を切り替えて再描画しても、各Actionは再描画1回につき1回ずつしか実行されない', function () {
        $user = User::factory()->create();
        chg32TestSeed();
        $component = Livewire::actingAs($user)->test(SignalList::class);

        chg32TestSpyOnSources(1);

        $component->call('setSort', 'recommended')->assertOk();
    });

    test('供給元の行を渡して分類しても、引数なしで分類した結果と完全に一致する', function () {
        // Arrange
        chg32TestSeed();
        $lossRows = app(ShowLossReviewListAction::class)->execute();
        $takeRows = app(ShowSignalListAction::class)->execute();
        $addOnRows = app(ShowBuySignalListAction::class)->execute();

        // Act
        $withoutArgs = app(ClassifyHoldingsAction::class)->execute();
        $withRows = app(ClassifyHoldingsAction::class)->execute($lossRows, $takeRows, $addOnRows);

        // Assert
        expect($withRows)->toEqual($withoutArgs);
        $codes = fn (array $result, string $bucket) => collect($result['buckets'])->firstWhere('bucket', $bucket)['holdings'];
        expect(array_column($codes($withRows, 'loss_review'), 'symbol_code'))->toBe(['6001'])
            ->and(array_column($codes($withRows, 'take_profit'), 'symbol_code'))->toBe(['6002'])
            ->and(array_column($codes($withRows, 'add_on'), 'symbol_code'))->toBe(['6003'])
            ->and(array_column($codes($withRows, 'hold'), 'symbol_code'))->toBe(['5001']);
    });

    test('渡した行が分類に使われ、供給元Actionは再実行されない', function () {
        chg32TestSeed();
        $lossRows = app(ShowLossReviewListAction::class)->execute();
        $takeRows = app(ShowSignalListAction::class)->execute();
        $addOnRows = app(ShowBuySignalListAction::class)->execute();

        chg32TestSpyOnSources(0);

        $result = app(ClassifyHoldingsAction::class)->execute($lossRows, $takeRows, $addOnRows);

        expect(collect($result['buckets'])->firstWhere('bucket', 'loss_review')['holdings'])->toHaveCount(1);
    });

    test('空配列を渡した場合は「該当なし」として扱い、供給元Actionを呼び直さない', function () {
        chg32TestSeed();
        chg32TestSpyOnSources(0);

        $result = app(ClassifyHoldingsAction::class)->execute([], [], []);

        $hold = collect($result['buckets'])->firstWhere('bucket', 'hold')['holdings'];
        expect(collect($hold)->pluck('symbol_code')->sort()->values()->all())->toBe(['5001', '6001', '6002', '6003']);
    });

    test('売買シグナル画面の4テーブルの表示内容は従来どおりで、キープ表に他の銘柄が混ざらない', function () {
        $user = User::factory()->create();
        chg32TestSeed();

        $html = Livewire::actingAs($user)->test(SignalList::class)->html();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        foreach (['整理銘柄', '利確銘柄', '買増銘柄', 'キープ銘柄'] as $name) {
            expect($text)->toContain($name);
        }
        $holdSection = substr($text, (int) strpos($text, 'キープ（ホールド）'));
        expect($holdSection)->toContain('キープ銘柄')
            ->not->toContain('整理銘柄')
            ->not->toContain('利確銘柄')
            ->not->toContain('買増銘柄');
    });
});
