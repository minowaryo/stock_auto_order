<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\StockPerformanceCalculator;
use App\Services\TradeReview\Support\PeriodPerformance;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-018 株式部分の年率・20%/25%判定・対ガチホ — Red phase (CHG-0033 Cycle 7b)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-018 基本フロー1・2・3、異常時
|   - docs/product/trade-decision-effect-gate2-uc-draft.md 決定1・年率目標と比較単位
|     r_t = (V_t − V_{t−1} − F_t) / (V_{t−1} + 0.5 × F_t)（分母0以下の週は除き件数を出す）
|     R = Π(1 + r_t) − 1、対ガチホ = 起点週の株数を持ち続けた場合の変化率との差（ポイント）
|     短い期間は期間目標 T(g,d) = (1 + g)^(d/365) − 1 の参考比較、5%超の欠損は判定保留
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - PortfolioWeek (7a) gains, per holding: holdingValuesJpy, holdingFlowsJpy
|     (valued holdings only), excludedEstimatesJpy, and pricesJpy (JPY per
|     split-adjusted share for every traded holding that has a close — and
|     USD/JPY for US — that week, held or not). Existing totals unchanged.
|   - App\Services\TradeReview\StockPerformanceCalculator::calculate(
|       array<string, PortfolioWeek> $weeks, array<int, string> $markets
|       (holding_id => 'jp'|'us'), ?string $market, string $baseWeek,
|       string $endWeek, int $judgeWeeks = 52): PeriodPerformance
|   - Weekly returns for each week in (baseWeek, endWeek] against the week
|     before, over the holdings of $market that are NOT excluded in either of
|     the two weeks (a holding without a close one week must not look like a
|     loss). A week whose denominator is <= 0 is skipped and counted.
|   - verdict: 'reference' when fewer than $judgeWeeks weeks lie in the
|     period (periodTarget20 / periodTarget25 = T(0.20 / 0.25, days from
|     baseWeek to endWeek)); else 'pending' when any week in [baseWeek,
|     endWeek] has excluded estimate / (value + estimate) > 5% (for $market);
|     else 'below_20' (R < 20%), 'met_20' (20% <= R < 25%), 'met_25'
|     (R >= 25%). 'unavailable' when no week could be computed.
|   - buyHoldRate: the shares held at the end of baseWeek, valued at
|     endWeek prices vs baseWeek prices (holdings priced in both weeks);
|     diffPoints = (returnRate − buyHoldRate) × 100.
|
| Expected Red: the calculator, PeriodPerformance and the new PortfolioWeek
| fields do not exist yet.
|
*/

/**
 * A hand-made week. Totals are derived from the per-holding arrays.
 *
 * @param  array<int, float>  $values  holding_id => JPY (valued holdings)
 * @param  array<int, float>  $flows
 * @param  array<int, float>  $excluded  holding_id => estimate JPY (reason no_price)
 * @param  array<int, float>  $quantities
 * @param  array<int, float>  $prices
 */
function pfWeek(string $week, array $values, array $flows = [], array $excluded = [], array $quantities = [], array $prices = []): PortfolioWeek
{
    return new PortfolioWeek(
        week: $week,
        valueJpy: array_sum($values),
        flowJpy: array_sum($flows),
        quantities: $quantities,
        excluded: array_map(fn () => 'no_price', $excluded),
        excludedEstimateJpy: array_sum($excluded),
        holdingValuesJpy: $values,
        holdingFlowsJpy: $flows,
        excludedEstimatesJpy: $excluded,
        pricesJpy: $prices,
    );
}

/**
 * @param  list<PortfolioWeek>  $weeks
 */
function pfCalc(array $weeks, string $base, string $end, int $judgeWeeks, array $markets = [], ?string $market = null): PeriodPerformance
{
    $keyed = [];
    foreach ($weeks as $week) {
        $keyed[$week->week] = $week;
    }
    $ids = [];
    foreach ($weeks as $week) {
        $ids += array_fill_keys(array_keys($week->holdingValuesJpy + $week->holdingFlowsJpy + $week->excludedEstimatesJpy + $week->quantities), 'jp');
    }

    return app(StockPerformanceCalculator::class)->calculate($keyed, $markets + $ids, $market, $base, $end, $judgeWeeks);
}

describe('UC-018 年率（時間加重リターン・修正ディーツ法）', function () {
    test('週ごとの修正ディーツ法のリターンをつないで期間のリターンにする（買付の資金は分母に半分だけ入る）', function () {
        // Arrange: +50 bought in week 1; 100 → 160 → 172.8
        $weeks = [
            pfWeek('2026-08-31', [1 => 100.0]),
            pfWeek('2026-09-07', [1 => 160.0], [1 => 50.0]),   // (160 − 100 − 50) / (100 + 25) = 0.08
            pfWeek('2026-09-14', [1 => 172.8]),                // 0.08
        ];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-14', judgeWeeks: 2);

        // Assert
        expect($p->returnRate)->toEqualWithDelta(1.08 * 1.08 - 1, 1e-9);
        expect([$p->weeksUsed, $p->weeksSkipped, $p->verdict])->toBe([2, 0, 'below_20']);
    });

    test('20%以上で「20%達成・25%未達」、25%以上で「25%達成」、20%未満で「20%未達」と判定する', function (float $end, string $verdict) {
        // Arrange
        $weeks = [pfWeek('2026-08-31', [1 => 100.0]), pfWeek('2026-09-07', [1 => $end])];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-07', judgeWeeks: 1);

        // Assert
        expect($p->verdict)->toBe($verdict);
    })->with([
        '19.99%' => [119.99, 'below_20'],
        '20%ちょうど' => [120.0, 'met_20'],
        '24.99%' => [124.99, 'met_20'],
        '25%ちょうど' => [125.0, 'met_25'],
    ]);

    test('分母が0以下の週（保有も買付もない週）は計算から除き、その件数を出す', function () {
        // Arrange
        $weeks = [
            pfWeek('2026-08-31', []),
            pfWeek('2026-09-07', []),                          // 0 / 0: skipped
            pfWeek('2026-09-14', [1 => 100.0], [1 => 100.0]),  // (100 − 0 − 100) / (0 + 50) = 0
        ];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-14', judgeWeeks: 2);

        // Assert
        expect([$p->weeksUsed, $p->weeksSkipped])->toBe([1, 1]);
        expect($p->returnRate)->toEqualWithDelta(0.0, 1e-9);
    });

    test('ある週に終値のない銘柄は、その週と前の週の比較から外し、見かけの損にしない', function () {
        // Arrange: B has no close in week 1
        $weeks = [
            pfWeek('2026-08-31', [1 => 1000.0, 2 => 1000.0]),
            pfWeek('2026-09-07', [1 => 1100.0], excluded: [2 => 50.0]),
        ];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-07', judgeWeeks: 1);

        // Assert: A alone: +10%, not (1100 − 2000) / 2000
        expect($p->returnRate)->toEqualWithDelta(0.10, 1e-9);
        expect($p->maxExcludedRatio)->toEqualWithDelta(50 / 1150, 1e-9);
        expect($p->verdict)->toBe('below_20'); // 4.3% <= 5%: still judged
    });

    test('外した銘柄の見積額が評価額の5%を超える週があれば、判定保留にする', function () {
        // Arrange
        $weeks = [
            pfWeek('2026-08-31', [1 => 1000.0, 2 => 1000.0]),
            pfWeek('2026-09-07', [1 => 1300.0], excluded: [2 => 1000.0]),
        ];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-07', judgeWeeks: 1);

        // Assert
        expect($p->verdict)->toBe('pending');
        expect($p->returnRate)->toEqualWithDelta(0.30, 1e-9); // still shown
    });

    test('判定に必要な週数に満たない期間は判定せず、期間目標（年20%・25%を日数で割り戻した値）との参考比較にする', function () {
        // Arrange: 2 weeks = 14 days
        $weeks = [
            pfWeek('2026-08-31', [1 => 100.0]),
            pfWeek('2026-09-07', [1 => 101.0]),
            pfWeek('2026-09-14', [1 => 102.0]),
        ];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-14', judgeWeeks: 52);

        // Assert
        expect($p->verdict)->toBe('reference');
        expect($p->periodTarget20)->toEqualWithDelta(1.2 ** (14 / 365) - 1, 1e-12);
        expect($p->periodTarget25)->toEqualWithDelta(1.25 ** (14 / 365) - 1, 1e-12);
    });

    test('計算できる週が1つもなければ「算出不可」にする', function () {
        expect(pfCalc([pfWeek('2026-08-31', []), pfWeek('2026-09-07', [])], '2026-08-31', '2026-09-07', judgeWeeks: 1)->verdict)
            ->toBe('unavailable');
    });
});

describe('UC-018 対ガチホ・市場別', function () {
    test('起点週の株数を持ち続けた場合の変化率を出し、実績との差をポイントで出す', function () {
        // Arrange: base: A 10 × 100, B 5 × 200. In week 1, B is sold at 150 (−750) and C bought (+750, worth 900 at the end)
        $weeks = [
            pfWeek('2026-08-31', [1 => 1000.0, 2 => 1000.0], quantities: [1 => 10.0, 2 => 5.0], prices: [1 => 100.0, 2 => 200.0, 3 => 50.0]),
            pfWeek('2026-09-07', [1 => 1200.0, 2 => 0.0, 3 => 900.0], [2 => -750.0, 3 => 750.0], quantities: [1 => 10.0, 3 => 15.0], prices: [1 => 120.0, 2 => 150.0, 3 => 60.0]),
        ];

        // Act
        $p = pfCalc($weeks, '2026-08-31', '2026-09-07', judgeWeeks: 1);

        // Assert: R = (2100 − 2000 − 0) / 2000 = 5%; buy & hold = (1200 + 750) / 2000 − 1 = −2.5%
        expect($p->returnRate)->toEqualWithDelta(0.05, 1e-9);
        expect($p->buyHoldRate)->toEqualWithDelta(-0.025, 1e-9);
        expect($p->diffPoints)->toEqualWithDelta(7.5, 1e-9);
    });

    test('市場を指定すると、その市場の銘柄だけで年率・対ガチホを出す', function () {
        // Arrange: 1 = jp (+10%), 2 = us (+50%)
        $weeks = [
            pfWeek('2026-08-31', [1 => 100.0, 2 => 100.0], quantities: [1 => 1.0, 2 => 1.0], prices: [1 => 100.0, 2 => 100.0]),
            pfWeek('2026-09-07', [1 => 110.0, 2 => 150.0], quantities: [1 => 1.0, 2 => 1.0], prices: [1 => 110.0, 2 => 150.0]),
        ];

        // Act
        $jp = pfCalc($weeks, '2026-08-31', '2026-09-07', 1, [1 => 'jp', 2 => 'us'], 'jp');
        $us = pfCalc($weeks, '2026-08-31', '2026-09-07', 1, [1 => 'jp', 2 => 'us'], 'us');
        $all = pfCalc($weeks, '2026-08-31', '2026-09-07', 1, [1 => 'jp', 2 => 'us']);

        // Assert
        expect([$jp->returnRate, $us->returnRate, $all->returnRate])->toEqualWithDelta([0.10, 0.50, 0.30], 1e-9);
        expect($jp->buyHoldRate)->toEqualWithDelta(0.10, 1e-9);
    });
});

describe('UC-018 7aの結果から年率を出す（実DB）', function () {
    test('売買履歴から復元した週ごとの株式部分で、年率と対ガチホが手計算どおりになる', function () {
        // Arrange: 10 shares bought at 100; closes 100 → 110 → 121
        Carbon::setTestNow('2026-09-23 10:00:00'); // last confirmed week 2026-09-14
        $h = Holding::create([
            'symbol_code' => '1111', 'market' => 'jp', 'instrument_type' => 'stock',
            'symbol_name' => '銘柄1111', 'sector_classification_id' => null, 'first_detected_at' => now(),
        ]);
        app(WeeklyPriceRecorder::class)->recordHolding($h, [
            ['date' => '2026-08-31', 'close' => 100.0, 'volume' => 1],
            ['date' => '2026-09-07', 'close' => 110.0, 'volume' => 1],
            ['date' => '2026-09-14', 'close' => 121.0, 'volume' => 1],
        ]);
        $batch = TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
        TradeExecution::create([
            'holding_id' => $h->id, 'market' => 'jp', 'trade_date' => '2026-09-01', 'account_type' => 'specific', 'kind' => 'buy',
            'quantity' => 10, 'unit_price' => 100, 'price_currency' => 'jpy', 'settlement_currency' => 'jpy', 'settlement_amount_jpy' => 1000,
            'content_hash' => str_repeat('a', 64), 'occurrence_index' => 0, 'source_row' => [],
            'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
        ]);
        $weeks = app(WeeklyPortfolioBuilder::class)->build();

        // Act
        $p = app(StockPerformanceCalculator::class)->calculate($weeks, [$h->id => 'jp'], null, '2026-08-31', '2026-09-14', 2);

        // Assert
        expect($weeks['2026-09-14']->holdingValuesJpy)->toBe([$h->id => 1210.0]);
        expect($weeks['2026-09-14']->pricesJpy[$h->id])->toBe(121.0);
        expect($p->returnRate)->toEqualWithDelta(0.21, 1e-9);
        expect($p->buyHoldRate)->toEqualWithDelta(0.21, 1e-9);
        expect($p->verdict)->toBe('met_20');
    });
});
