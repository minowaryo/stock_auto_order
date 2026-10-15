<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PriceTrackingTarget;
use App\Models\StockSplit;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\Support\TradeComparison;
use App\Services\TradeReview\SwitchAllocator;
use App\Services\TradeReview\TradeComparisonCalculator;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-018 売却単独と推定乗換え — Red phase (CHG-0033 Cycle 7c-1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-018 基本フロー4・5、業務ルール、異常時
|   - docs/product/trade-decision-effect-gate2-uc-draft.md 決定2・決定5、
|     「乗換えの対応と二重集計の防止」
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\TradeReview\SwitchAllocator::allocate(): list<array{
|       sell_id: int, buy_id: int, amount_jpy: float}> — estimated switches:
|     buys (kind 'buy'; tsumitate excluded) in (trade_date, id) order draw
|     sell proceeds (JPY settlement, or USD × fx_rate) of the SAME market whose
|     sell date is on or up to 5 business days (weekdays) before the buy,
|     oldest sell first. A yen is never allocated twice; what a buy cannot
|     draw is new money, what a sell keeps is cash.
|   - App\Services\TradeReview\TradeComparisonCalculator, on the weeks of
|     WeeklyPortfolioBuilder (pricesJpy):
|       sells(array $weeks): list<TradeComparison> — one per sell and horizon
|         (4, 13, 26 weeks after the sell week): actualJpy = the proceeds
|         kept as cash, holdJpy = the sold shares (split-adjusted) × the
|         price at the evaluation week. diffJpy = actualJpy − holdJpy
|         (> 0: selling was better), diffRate = diffJpy / baseJpy.
|       switches(array $weeks): list<TradeComparison> — one per sell with
|         at least one allocation and horizon: actualJpy = Σ allocated /
|         buy cost × buy shares × buy price at the evaluation week + the
|         cash left; holdJpy and diff as for sells.
|     status 'pending' when the evaluation week is after the last confirmed
|     week; 'unavailable' with a reason (no_price, split_pending,
|     split_incomplete) when a needed price is missing; else 'ok'.
|   - TradeComparison (readonly): tradeId, type ('sell' | 'switch'),
|     horizon, evaluationWeek, status, reason, baseJpy, actualJpy, holdJpy,
|     diffJpy, diffRate.
|   - Business days are weekdays (Japanese holidays are not taken out).
|
| Expected Red: the allocator, the calculator and TradeComparison do not
| exist yet.
|
*/

function tcHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function tcTrade(Holding $holding, string $kind, string $date, float $quantity, float $jpy): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => 'specific', 'kind' => $kind, 'is_routine' => $kind === 'tsumitate',
        'quantity' => $quantity, 'unit_price' => $jpy / $quantity,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy', 'settlement_currency' => 'jpy',
        'settlement_amount_jpy' => $jpy, 'fx_rate' => $holding->market === 'us' ? 100.0 : null,
        'content_hash' => str_pad((string) (5000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * @param  array<string, float>  $closes  week Monday => close
 */
function tcPrices(Holding $holding, array $closes): void
{
    app(WeeklyPriceRecorder::class)->recordHolding($holding, array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 1],
        array_keys($closes), $closes,
    ));
}

/**
 * @return array<int, TradeComparison> keyed by horizon
 */
function tcFor(array $comparisons, TradeExecution $trade, string $type): array
{
    $found = [];
    foreach ($comparisons as $c) {
        if ($c->tradeId === $trade->id && $c->type === $type) {
            $found[$c->horizon] = $c;
        }
    }
    ksort($found);

    return $found;
}

function tcCalc(): TradeComparisonCalculator
{
    return app(TradeComparisonCalculator::class);
}

function tcWeeks(): array
{
    return app(WeeklyPortfolioBuilder::class)->build();
}

beforeEach(function () {
    // Wednesday: the last confirmed week is 2026-12-28; +26 weeks from September is still to come
    Carbon::setTestNow('2027-01-06 10:00:00');
});

describe('UC-018 売却単独（売却代金を現金で持った場合と、持ち続けた場合）', function () {
    test('売却の週から+4・+13週の評価週で、現金のままと持ち続けた場合の差を出し、+26週は結果待ちにする', function () {
        // Arrange: 10 shares sold for 1,000 in the week of 2026-09-07
        $a = tcHolding('1111');
        tcPrices($a, ['2026-09-07' => 100, '2026-10-05' => 120, '2026-12-07' => 90]);
        tcTrade($a, 'buy', '2026-09-01', 10, 900);
        $sell = tcTrade($a, 'sell', '2026-09-08', 10, 1000);

        // Act
        $c = tcFor(tcCalc()->sells(tcWeeks()), $sell, 'sell');

        // Assert
        expect(array_keys($c))->toBe([4, 13, 26]);
        expect([$c[4]->evaluationWeek, $c[4]->status, $c[4]->baseJpy, $c[4]->actualJpy, $c[4]->holdJpy, $c[4]->diffJpy])
            ->toBe(['2026-10-05', 'ok', 1000.0, 1000.0, 1200.0, -200.0]); // holding on was better by 200
        expect($c[4]->diffRate)->toEqualWithDelta(-0.2, 1e-9);
        expect([$c[13]->evaluationWeek, $c[13]->diffJpy])->toBe(['2026-12-07', 100.0]); // selling was better by 100
        expect([$c[26]->evaluationWeek, $c[26]->status, $c[26]->diffJpy])->toBe(['2027-03-08', 'pending', null]);
    });

    test('分割の後の評価週では、売った株数を分割後に揃えて評価する', function () {
        // Arrange: 10 shares sold before a 2:1 split; the closes are split-adjusted
        $a = tcHolding('1111');
        tcPrices($a, ['2026-09-07' => 50, '2026-10-05' => 60, '2026-12-07' => 60]);
        tcTrade($a, 'buy', '2026-09-01', 10, 1000);
        $sell = tcTrade($a, 'sell', '2026-09-08', 10, 1000);
        StockSplit::create(['holding_id' => $a->id, 'effective_date' => '2026-09-20', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']);

        // Act
        $c = tcFor(tcCalc()->sells(tcWeeks()), $sell, 'sell');

        // Assert: 20 adjusted shares × 60
        expect($c[4]->holdJpy)->toBe(1200.0);
    });

    test('評価週の価格がない・分割の取り直し待ちの銘柄は、理由つきの算出不可にし、0円にしない', function () {
        // Arrange
        $gap = tcHolding('1111');
        tcPrices($gap, ['2026-09-07' => 100, '2026-12-07' => 100]); // no close for 2026-10-05
        tcTrade($gap, 'buy', '2026-09-01', 10, 1000);
        $gapSell = tcTrade($gap, 'sell', '2026-09-08', 10, 1000);
        $pending = tcHolding('2222');
        tcPrices($pending, ['2026-09-07' => 100, '2026-10-05' => 100, '2026-12-07' => 100]);
        tcTrade($pending, 'buy', '2026-09-01', 10, 1000);
        $pendingSell = tcTrade($pending, 'sell', '2026-09-08', 10, 1000);
        PriceTrackingTarget::create(['holding_id' => $pending->id, 'status' => 'completed', 'full_history_fetched_at' => '2026-12-01 00:00:00']);
        StockSplit::create(['holding_id' => $pending->id, 'effective_date' => '2025-01-06', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']); // recorded now: after the fetch

        // Act
        $sells = tcCalc()->sells(tcWeeks());

        // Assert
        $g = tcFor($sells, $gapSell, 'sell');
        expect([$g[4]->status, $g[4]->reason, $g[4]->diffJpy])->toBe(['unavailable', 'no_price', null]);
        expect($g[13]->status)->toBe('ok');
        $p = tcFor($sells, $pendingSell, 'sell');
        expect([$p[4]->status, $p[4]->reason])->toBe(['unavailable', 'split_pending']);
    });
});

describe('UC-018 推定乗換え: 売却代金の割り当て（同じ市場・5営業日以内・約定日順）', function () {
    test('売却から5営業日以内の買付に、古い売却から順に割り当て、同じ金額を二重に割り当てない', function () {
        // Arrange: S1 500 (Mon), S2 500 (Tue); B1 700 (Wed) → S1 500 + S2 200; B2 600 (Thu) → S2 300, 300 new money
        $a = tcHolding('1111');
        $b = tcHolding('2222');
        tcTrade($a, 'buy', '2026-08-24', 100, 1000);
        $s1 = tcTrade($a, 'sell', '2026-09-07', 10, 500);
        $s2 = tcTrade($a, 'sell', '2026-09-08', 10, 500);
        $b1 = tcTrade($b, 'buy', '2026-09-09', 7, 700);
        $b2 = tcTrade($b, 'buy', '2026-09-10', 6, 600);

        // Act
        $allocations = app(SwitchAllocator::class)->allocate();

        // Assert
        expect($allocations)->toBe([
            ['sell_id' => $s1->id, 'buy_id' => $b1->id, 'amount_jpy' => 500.0],
            ['sell_id' => $s2->id, 'buy_id' => $b1->id, 'amount_jpy' => 200.0],
            ['sell_id' => $s2->id, 'buy_id' => $b2->id, 'amount_jpy' => 300.0],
        ]);
    });

    test('同じ日の買付は対象、5営業日目（翌週の同じ曜日）までが対象で、6営業日目は対象外', function () {
        // Arrange: sell on Friday 2026-09-04
        $a = tcHolding('1111');
        $b = tcHolding('2222');
        tcTrade($a, 'buy', '2026-08-24', 100, 3000);
        $sell = tcTrade($a, 'sell', '2026-09-04', 30, 3000);
        $sameDay = tcTrade($b, 'buy', '2026-09-04', 1, 100);
        $fifth = tcTrade($b, 'buy', '2026-09-11', 1, 100);  // Fri: 5 business days later
        $sixth = tcTrade($b, 'buy', '2026-09-14', 1, 100);  // Mon: 6 business days later

        // Act
        $buyIds = array_column(app(SwitchAllocator::class)->allocate(), 'buy_id');

        // Assert
        expect($buyIds)->toBe([$sameDay->id, $fifth->id]);
    });

    test('同じ日の売却と買付は、CSVの登録順（買付が先）に関係なく、売却代金を買付に割り当てる（レビューで発見）', function () {
        // Arrange: the buy row was imported before the sell row of the same day (the CSV order)
        $a = tcHolding('1111');
        $b = tcHolding('2222');
        tcTrade($a, 'buy', '2026-08-24', 100, 1000);
        $buy = tcTrade($b, 'buy', '2026-09-07', 3, 300);
        $sell = tcTrade($a, 'sell', '2026-09-07', 10, 500);

        // Act / Assert
        expect(app(SwitchAllocator::class)->allocate())->toBe([
            ['sell_id' => $sell->id, 'buy_id' => $buy->id, 'amount_jpy' => 300.0],
        ]);
    });

    test('別の市場の買付と、積立には割り当てない', function () {
        // Arrange
        $jp = tcHolding('1111');
        $us = tcHolding('AAPL', 'us');
        tcTrade($jp, 'buy', '2026-08-24', 100, 1000);
        tcTrade($jp, 'sell', '2026-09-07', 10, 500);
        tcTrade($us, 'buy', '2026-09-08', 1, 300);
        tcTrade(tcHolding('2222'), 'tsumitate', '2026-09-08', 1, 100);

        // Act / Assert
        expect(app(SwitchAllocator::class)->allocate())->toBe([]);
    });
});

describe('UC-018 推定乗換えの評価（参考）', function () {
    test('割り当てた買付の評価額＋現金のまま分と、元の株を持ち続けた場合を比べる', function () {
        // Arrange: A: 10 sold for 1,000; B: 6 bought for 600 within the window (400 stays cash)
        $a = tcHolding('1111');
        $b = tcHolding('2222');
        tcPrices($a, ['2026-09-07' => 100, '2026-10-05' => 110, '2026-12-07' => 110]);
        tcPrices($b, ['2026-09-07' => 100, '2026-10-05' => 150, '2026-12-07' => 150]);
        tcTrade($a, 'buy', '2026-08-24', 10, 1000);
        $sell = tcTrade($a, 'sell', '2026-09-07', 10, 1000);
        tcTrade($b, 'buy', '2026-09-09', 6, 600);

        // Act
        $c = tcFor(tcCalc()->switches(tcWeeks()), $sell, 'switch');

        // Assert: 6 × 150 + 400 = 1,300 against 10 × 110 = 1,100
        expect([$c[4]->baseJpy, $c[4]->actualJpy, $c[4]->holdJpy, $c[4]->diffJpy])->toBe([1000.0, 1300.0, 1100.0, 200.0]);
        expect($c[26]->status)->toBe('pending');
    });

    test('割り当てのない売却は、推定乗換えの結果を作らない（売却単独だけ）', function () {
        // Arrange
        $a = tcHolding('1111');
        tcPrices($a, ['2026-09-07' => 100, '2026-10-05' => 100, '2026-12-07' => 100]);
        tcTrade($a, 'buy', '2026-08-24', 10, 1000);
        $sell = tcTrade($a, 'sell', '2026-09-07', 10, 1000);
        $weeks = tcWeeks();

        // Act / Assert
        expect(tcFor(tcCalc()->switches($weeks), $sell, 'switch'))->toBe([]);
        expect(tcFor(tcCalc()->sells($weeks), $sell, 'sell'))->toHaveCount(3);
    });
});
