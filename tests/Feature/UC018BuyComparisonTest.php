<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\Support\TradeComparison;
use App\Services\TradeReview\TradeComparisonCalculator;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-018 買付の比例買増し・指数との比較 — Red phase (CHG-0033 Cycle 7c-2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-018 基本フロー6、異常時（同じ市場に保有が
|     ない週の買付は比例買増しを算出不可とし、指数比較のみ表示）
|   - docs/product/trade-decision-effect-gate2-uc-draft.md 決定4・決定5
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - TradeComparisonCalculator::buys(array $weeks): list<TradeComparison>,
|     type 'buy', one per buy (kind 'buy'; tsumitate is never compared) that
|     has new money left after the estimated switches (SwitchAllocator), and
|     per horizon 4 / 13 / 26 weeks after the buy week.
|       baseJpy   = the new money (cost − allocated)
|       actualJpy = new money / cost × split-adjusted shares × price at the
|                   evaluation week
|       holdJpy   = the new money spread over the same market's holdings by
|                   their value at the end of the week BEFORE the buy week
|                   (the bought holding included when held), bought at the
|                   buy week's prices and valued at the evaluation week.
|                   A composition holding without a price in the buy week or
|                   the evaluation week is dropped and the rest re-weighted,
|                   unless the dropped weight exceeds 5% → unavailable
|                   'no_price'.
|       No holding of the market in the week before → status 'unavailable',
|       reason 'no_holdings' (holdJpy / diffJpy null), the index comparison
|       still given.
|       indexJpy  = new money × index(eval) / index(buy week): nikkei225 for
|                   jp, sp500 × usdjpy for us; diffIndexJpy = actualJpy −
|                   indexJpy (reference). TradeComparison gains indexJpy and
|                   diffIndexJpy (null for sells / switches).
|
| Expected Red: buys() and the index fields do not exist yet.
|
*/

function bcHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function bcTrade(Holding $holding, string $kind, string $date, float $quantity, float $jpy): TradeExecution
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
        'content_hash' => str_pad((string) (3000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * @param  array<string, float>  $closes
 */
function bcPrices(Holding $holding, array $closes): void
{
    app(WeeklyPriceRecorder::class)->recordHolding($holding, array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 1],
        array_keys($closes), $closes,
    ));
}

/**
 * @param  array<string, float>  $closes
 */
function bcIndex(string $name, array $closes): void
{
    app(WeeklyPriceRecorder::class)->recordIndex($name, array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 0],
        array_keys($closes), $closes,
    ));
}

/**
 * @return array<int, TradeComparison> keyed by horizon
 */
function bcBuysOf(TradeExecution $trade): array
{
    $found = [];
    foreach (app(TradeComparisonCalculator::class)->buys(app(WeeklyPortfolioBuilder::class)->build()) as $c) {
        if ($c->tradeId === $trade->id) {
            $found[$c->horizon] = $c;
        }
    }
    ksort($found);

    return $found;
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-06 10:00:00'); // last confirmed week 2026-12-28
    bcIndex('nikkei225', ['2026-09-07' => 30000, '2026-10-05' => 33000]);
});

describe('UC-018 買付: 既存保有の比例買増しとの比較', function () {
    test('買付前週末の同じ市場の保有構成どおりに買い増した場合と比べ、指数との差も参考に付ける', function () {
        // Arrange: week before (2026-08-31): A 10 × 60 = 600, B 10 × 40 = 400 (60% / 40%)
        $a = bcHolding('1111');
        $b = bcHolding('2222');
        $c = bcHolding('3333');
        bcPrices($a, ['2026-08-31' => 60, '2026-09-07' => 60, '2026-10-05' => 66]);
        bcPrices($b, ['2026-08-31' => 40, '2026-09-07' => 40, '2026-10-05' => 36]);
        bcPrices($c, ['2026-09-07' => 100, '2026-10-05' => 120]);
        bcTrade($a, 'buy', '2026-08-24', 10, 500);
        bcTrade($b, 'buy', '2026-08-24', 10, 500);
        $buy = bcTrade($c, 'buy', '2026-09-08', 10, 1000);

        // Act
        $r = bcBuysOf($buy)[4];

        // Assert: proportional 1,000 → A 600 / 60 = 10 sh, B 400 / 40 = 10 sh → 10 × 66 + 10 × 36 = 1,020
        expect([$r->type, $r->evaluationWeek, $r->status, $r->baseJpy, $r->actualJpy, $r->holdJpy, $r->diffJpy])
            ->toBe(['buy', '2026-10-05', 'ok', 1000.0, 1200.0, 1020.0, 180.0]);
        expect([$r->indexJpy, $r->diffIndexJpy])->toBe([1100.0, 100.0]); // 1,000 × 33,000 / 30,000
    });

    test('すでに持っている銘柄の買い増しでも、比例配分の構成にその銘柄自体を含める', function () {
        // Arrange: week before: A 500, C 500; C is bought again
        $a = bcHolding('1111');
        $c = bcHolding('3333');
        bcPrices($a, ['2026-08-31' => 50, '2026-09-07' => 50, '2026-10-05' => 50]);
        bcPrices($c, ['2026-08-31' => 100, '2026-09-07' => 100, '2026-10-05' => 120]);
        bcTrade($a, 'buy', '2026-08-24', 10, 500);
        bcTrade($c, 'buy', '2026-08-24', 5, 500);
        $buy = bcTrade($c, 'buy', '2026-09-08', 10, 1000);

        // Act
        $r = bcBuysOf($buy)[4];

        // Assert: proportional → A 500 / 50 = 10 sh, C 500 / 100 = 5 sh → 500 + 600 = 1,100; actual 10 × 120
        expect([$r->actualJpy, $r->holdJpy, $r->diffJpy])->toBe([1200.0, 1100.0, 100.0]);
    });

    test('推定乗換えに割り当てた部分は除き、新規資金の部分だけを比べる（全額割り当てなら比べない）', function () {
        // Arrange: A sold for 600 on Monday; D bought for 300 the same day draws 300 of it (no new money);
        // C bought for 1,000 on Tuesday draws the other 300 (700 new money)
        $a = bcHolding('1111');
        $c = bcHolding('3333');
        $d = bcHolding('4444');
        bcPrices($a, ['2026-08-31' => 100, '2026-09-07' => 100, '2026-10-05' => 100]);
        bcPrices($c, ['2026-09-07' => 100, '2026-10-05' => 120]);
        bcPrices($d, ['2026-09-07' => 100, '2026-10-05' => 100]);
        bcTrade($a, 'buy', '2026-08-24', 20, 2000);
        bcTrade($a, 'sell', '2026-09-07', 6, 600);
        $full = bcTrade($d, 'buy', '2026-09-07', 3, 300);
        $part = bcTrade($c, 'buy', '2026-09-08', 10, 1000);

        // Act / Assert
        expect(bcBuysOf($full))->toBe([]);
        $r = bcBuysOf($part)[4];
        expect([$r->baseJpy, $r->actualJpy])->toBe([700.0, 840.0]); // 700 / 1,000 × 10 × 120
    });

    test('同じ市場に前週末の保有がない買付は、比例買増しを算出不可にし、指数との比較だけ出す（米国株はS&P500をドル円で円換算）', function () {
        // Arrange
        $u = bcHolding('NVDA', 'us');
        bcPrices($u, ['2026-09-07' => 10, '2026-10-05' => 12]);
        bcIndex('usdjpy', ['2026-09-07' => 100, '2026-10-05' => 110]);
        bcIndex('sp500', ['2026-09-07' => 5000, '2026-10-05' => 5500]);
        $buy = bcTrade($u, 'buy', '2026-09-08', 1, 1000);

        // Act
        $r = bcBuysOf($buy)[4];

        // Assert: actual 1 × 12 × 110 = 1,320; index 1,000 × (5,500 × 110) / (5,000 × 100) = 1,210
        expect([$r->status, $r->reason, $r->holdJpy, $r->diffJpy])->toBe(['unavailable', 'no_holdings', null, null]);
        expect([$r->actualJpy, $r->indexJpy, $r->diffIndexJpy])->toBe([1320.0, 1210.0, 110.0]);
    });

    test('構成銘柄に評価週の価格がなければ、5%以下なら外して残りで配分し直し、5%を超えれば算出不可にする', function (float $bValue, string $status, ?float $hold) {
        // Arrange: week before: A 1,000 − $bValue, B $bValue; B has no close in the evaluation week
        $a = bcHolding('1111');
        $b = bcHolding('2222');
        $c = bcHolding('3333');
        bcPrices($a, ['2026-08-31' => 1, '2026-09-07' => 1, '2026-10-05' => 1.1]);
        bcPrices($b, ['2026-08-31' => 1, '2026-09-07' => 1]);
        bcPrices($c, ['2026-09-07' => 100, '2026-10-05' => 100]);
        bcTrade($a, 'buy', '2026-08-24', 1000 - $bValue, 1000 - $bValue);
        bcTrade($b, 'buy', '2026-08-24', $bValue, $bValue);
        $buy = bcTrade($c, 'buy', '2026-09-08', 10, 1000);

        // Act
        $r = bcBuysOf($buy)[4];

        // Assert
        expect($r->status)->toBe($status);
        $hold === null ? expect($r->holdJpy)->toBeNull() : expect($r->holdJpy)->toEqualWithDelta($hold, 1e-6);
    })->with([
        '5%ちょうど: Bを外してAだけで1,000 → 1,100' => [50.0, 'ok', 1100.0],
        '6%: 算出不可' => [60.0, 'unavailable', null],
    ]);

    test('積立の買付と、評価週が未来の買付は、比べない／結果待ちにする', function () {
        // Arrange
        $a = bcHolding('1111');
        bcPrices($a, ['2026-08-31' => 100, '2026-09-07' => 100, '2026-10-05' => 100, '2026-12-07' => 100]);
        bcTrade($a, 'buy', '2026-08-24', 10, 1000);
        $routine = bcTrade($a, 'tsumitate', '2026-09-08', 1, 100);
        $buy = bcTrade($a, 'buy', '2026-09-08', 1, 100);

        // Act / Assert
        expect(bcBuysOf($routine))->toBe([]);
        $r = bcBuysOf($buy);
        expect([$r[4]->status, $r[13]->status, $r[26]->status])->toBe(['ok', 'ok', 'pending']);
    });
});
