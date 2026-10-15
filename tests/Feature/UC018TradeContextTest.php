<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\IndicatorObservation;
use App\Models\SignalOccurrence;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\Support\TradeContext;
use App\Services\TradeReview\TradeContextFinder;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-018 売買直前の状況の紐付け — Red phase (CHG-0033 Cycle 7d)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-018 基本フロー7（売買前に保存済みのシグナル・
|     指標・保有状況を自動で紐付けて表示し、本人の動機とは断定しない）
|   - docs/adr/ADR-0027 D4（2026-10-11改訂: 表を作らず、約定日の開始より前・
|     8日以内の記録を読み取り時に紐付ける）
|   - 本人方針（2026-10-11）: ロジックの版を持たない・表示しない、データを増やさない
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\TradeReview\TradeContextFinder::find(array<string,
|     PortfolioWeek> $weeks): array<int, TradeContext> keyed by trade id,
|     for every buy / sell / tsumitate.
|   - The window is [start − 8 days, start), start = 00:00 of the trade date
|     in the market's time zone (jp Asia/Tokyo, us America/New_York); the
|     time a record was made (signal_occurrences.created_at,
|     indicator_observations.observed_at) must fall in it — only what the
|     system already knew before the trade day.
|   - TradeContext (readonly): tradeId; signals (list of {source,
|     signal_type, observed_week} of the same holding, in time order);
|     metrics (the latest indicator observation in the window, else the
|     latest linked signal's metrics, else null); observedAt (when those
|     metrics were recorded); known (signals or metrics exist — otherwise
|     the situation is unknown); heldBefore (shares at the end of the week
|     before the trade week) and weightBefore (its share of the same
|     market's value then, 0.0 when not held).
|
| Expected Red: TradeContextFinder and TradeContext do not exist yet.
|
*/

function ctHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function ctTrade(Holding $holding, string $kind, string $date, float $quantity = 10, float $jpy = 1000): TradeExecution
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
        'content_hash' => str_pad((string) (1000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * A signal recorded at $createdAt (UTC).
 */
function ctSignal(Holding $holding, string $type, string $observedWeek, string $createdAt, array $metrics = ['rsi' => 75.0]): SignalOccurrence
{
    $signal = SignalOccurrence::create([
        'holding_id' => $holding->id, 'source' => 'take_profit', 'signal_type' => $type,
        'observed_week' => $observedWeek, 'metrics' => $metrics,
    ]);
    $signal->forceFill(['created_at' => $createdAt])->save();

    return $signal;
}

function ctObservation(Holding $holding, string $observedAt, array $metrics): void
{
    IndicatorObservation::create([
        'holding_id' => $holding->id, 'source' => 'holding_import', 'observed_at' => $observedAt, 'metrics' => $metrics,
    ]);
}

/**
 * @return array<int, TradeContext>
 */
function ctFind(): array
{
    return app(TradeContextFinder::class)->find(app(WeeklyPortfolioBuilder::class)->build());
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
});

describe('UC-018 売買直前の状況: 当時の記録の紐付け', function () {
    test('約定日の開始（国内株は日本時間）より前・8日以内に記録されたシグナルだけを紐付ける', function () {
        // Arrange: trade on 2026-09-10 → the day starts at 2026-09-09 15:00 UTC; 8 days before = 2026-09-01 15:00 UTC
        $a = ctHolding('1111');
        $trade = ctTrade($a, 'sell', '2026-09-10');
        ctSignal($a, 'too_old', '2026-08-31', '2026-09-01 14:59:59');
        ctSignal($a, 'oldest_in', '2026-08-31', '2026-09-01 15:00:00');
        ctSignal($a, 'last_in', '2026-09-07', '2026-09-09 14:59:59');
        ctSignal($a, 'on_the_day', '2026-09-07', '2026-09-09 15:00:00');

        // Act
        $context = ctFind()[$trade->id];

        // Assert
        expect(array_column($context->signals, 'signal_type'))->toBe(['oldest_in', 'last_in']);
        expect($context->signals[0])->toBe(['source' => 'take_profit', 'signal_type' => 'oldest_in', 'observed_week' => '2026-08-31']);
        expect($context->known)->toBeTrue();
    });

    test('米国株はニューヨーク時間の約定日の開始で区切る', function () {
        // Arrange: 2026-09-10 starts at 04:00 UTC in New York (EDT)
        $u = ctHolding('NVDA', 'us');
        $trade = ctTrade($u, 'buy', '2026-09-10');
        ctSignal($u, 'before', '2026-09-07', '2026-09-10 03:59:59');
        ctSignal($u, 'after', '2026-09-07', '2026-09-10 04:00:00');

        // Act / Assert
        expect(array_column(ctFind()[$trade->id]->signals, 'signal_type'))->toBe(['before']);
    });

    test('期間内で最新の指標の記録を状況の値にし、約定日の開始以後の記録は使わない', function () {
        // Arrange
        $a = ctHolding('1111');
        $trade = ctTrade($a, 'buy', '2026-09-10');
        ctObservation($a, '2026-09-05 01:00:00', ['rsi' => 40.0]);
        ctObservation($a, '2026-09-08 01:00:00', ['rsi' => 45.0]);
        ctObservation($a, '2026-09-09 16:00:00', ['rsi' => 99.0]); // after the trade day began (JST)

        // Act
        $context = ctFind()[$trade->id];

        // Assert
        expect([$context->metrics, $context->observedAt])->toEqual([['rsi' => 45.0], '2026-09-08 01:00:00']); // JSON gives 45.0 back as 45
        expect($context->signals)->toBe([]);
        expect($context->known)->toBeTrue();
    });

    test('指標の記録がなければ、紐付けたシグナルのうち最新のものの指標の値を使う', function () {
        // Arrange
        $a = ctHolding('1111');
        $trade = ctTrade($a, 'sell', '2026-09-10');
        ctSignal($a, 'first', '2026-08-31', '2026-09-02 01:00:00', ['rsi' => 71.0]);
        ctSignal($a, 'second', '2026-09-07', '2026-09-08 01:00:00', ['rsi' => 76.0]);

        // Act
        $context = ctFind()[$trade->id];

        // Assert
        expect([$context->metrics, $context->observedAt])->toEqual([['rsi' => 76.0], '2026-09-08 01:00:00']); // JSON gives 45.0 back as 45
    });

    test('記録が何もない売買は「状況不明」にし、ほかの銘柄の記録は紐付けない', function () {
        // Arrange
        $a = ctHolding('1111');
        $other = ctHolding('2222');
        $trade = ctTrade($a, 'buy', '2026-09-10');
        ctSignal($other, 'other_holding', '2026-09-07', '2026-09-08 01:00:00');
        ctObservation($other, '2026-09-08 01:00:00', ['rsi' => 50.0]);

        // Act
        $context = ctFind()[$trade->id];

        // Assert
        expect([$context->known, $context->signals, $context->metrics, $context->observedAt])->toBe([false, [], null, null]);
    });
});

describe('UC-018 売買直前の状況: 保有状況', function () {
    test('売買週の前週末に持っていたかと、同じ市場の評価額に占める割合を出す', function () {
        // Arrange: week before 2026-09-07 is 2026-08-31: A 10 × 60 = 600, B 10 × 40 = 400
        $a = ctHolding('1111');
        $b = ctHolding('2222');
        $c = ctHolding('3333');
        app(WeeklyPriceRecorder::class)->recordHolding($a, [['date' => '2026-08-31', 'close' => 60.0, 'volume' => 1], ['date' => '2026-09-07', 'close' => 60.0, 'volume' => 1]]);
        app(WeeklyPriceRecorder::class)->recordHolding($b, [['date' => '2026-08-31', 'close' => 40.0, 'volume' => 1], ['date' => '2026-09-07', 'close' => 40.0, 'volume' => 1]]);
        app(WeeklyPriceRecorder::class)->recordHolding($c, [['date' => '2026-09-07', 'close' => 100.0, 'volume' => 1]]);
        ctTrade($a, 'buy', '2026-08-25', 10, 500);
        ctTrade($b, 'buy', '2026-08-25', 10, 500);
        $more = ctTrade($a, 'buy', '2026-09-08', 1, 60);
        $new = ctTrade($c, 'buy', '2026-09-08', 1, 100);

        // Act
        $contexts = ctFind();

        // Assert
        expect([$contexts[$more->id]->heldBefore, $contexts[$more->id]->weightBefore])->toBe([true, 0.6]);
        expect([$contexts[$new->id]->heldBefore, $contexts[$new->id]->weightBefore])->toBe([false, 0.0]);
    });
});
