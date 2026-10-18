<?php

namespace Tests\Feature;

use App\Actions\TradeReview\ShowTradeReviewAction;
use App\Models\Holding;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\StockPerformanceCalculator;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| UC-018 公開後に見つけた3点の修正 — Red phase (CHG-0033, 2026-10-18 本人承認)
|--------------------------------------------------------------------------
|
| 1. The cached report must be rebuilt when the CODE that builds it changed,
|    not only when the data changed: ShowTradeReviewAction keeps a
|    fingerprint of the trade review code next to the data signature
|    ('code' in the cache entry) and rebuilds on a mismatch.
| 2. A period is judged per market from the week that market's holdings
|    begin: a market that starts within the year (US in Aug 2024) is
|    「参考」 for that year and for the latest 52 weeks, while a market with
|    the whole period stays judged.
| 3. Modified Dietz weights each flow by the trading days it was invested
|    in its week (Mon 1.0, Tue 0.8, Wed 0.6, Thu 0.4, Fri 0.2) instead of a
|    flat 0.5: PortfolioWeek gains holdingWeightedFlowsJpy (flow × weight
|    per holding); the calculator uses it, and falls back to 0.5 × flow
|    when a week has none (hand-made weeks).
|
*/

function rfHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function rfTrade(Holding $holding, string $kind, string $date, float $quantity, float $jpy): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => 'specific', 'kind' => $kind, 'is_routine' => false,
        'quantity' => $quantity, 'unit_price' => $jpy / $quantity,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy', 'settlement_currency' => 'jpy',
        'settlement_amount_jpy' => $jpy, 'fx_rate' => $holding->market === 'us' ? 100.0 : null,
        'content_hash' => str_pad((string) (8800 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * Weekly closes every Monday from $from to $to.
 */
function rfWeekly(Holding $holding, string $from, string $to, float $close): void
{
    $rows = [];
    for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addWeek()) {
        $rows[] = ['date' => $d->toDateString(), 'close' => $close, 'volume' => 1];
    }
    app(WeeklyPriceRecorder::class)->recordHolding($holding, $rows);
}

describe('1. キャッシュはコードが変わったときも作り直す', function () {
    test('データが同じでも、古いコードで作られたキャッシュは使わずに作り直す', function () {
        // Arrange: a real report is cached, then the entry is made to look built by older code
        Carbon::setTestNow('2026-10-07 10:00:00');
        rfTrade(rfHolding('1111'), 'buy', '2026-09-08', 1, 100);
        $action = app(ShowTradeReviewAction::class);
        $action->execute();
        $entry = Cache::get('trade-review');
        expect($entry)->toHaveKey('code');
        Cache::forever('trade-review', ['signature' => $entry['signature'], 'code' => 'built-by-older-code', 'report' => ['latest' => null, 'years' => [], 'trades' => []]]);

        // Act
        $report = $action->execute();

        // Assert: rebuilt, so the trade is back
        expect($report['trades'])->toHaveCount(1);
    });
});

describe('2. 市場ごとに、その市場の保有が始まった週から期間を数える', function () {
    test('年の途中から始まった米国株はその年も直近52週も「参考」、通年ある国内株は判定する', function () {
        // Arrange: JP held from 2024-12-30, US only from 2025-08-04
        Carbon::setTestNow('2026-01-14 10:00:00'); // last confirmed week 2026-01-05
        $jp = rfHolding('1111');
        $us = rfHolding('NVDA', 'us');
        rfWeekly($jp, '2024-12-30', '2026-01-05', 100.0);
        rfWeekly($us, '2025-08-04', '2026-01-05', 10.0);
        $fx = [];
        for ($d = Carbon::parse('2025-08-04'); $d->lte(Carbon::parse('2026-01-05')); $d->addWeek()) {
            $fx[] = ['date' => $d->toDateString(), 'close' => 100.0, 'volume' => 0];
        }
        app(WeeklyPriceRecorder::class)->recordIndex('usdjpy', $fx);
        rfTrade($jp, 'buy', '2024-12-31', 10, 1000);
        rfTrade($us, 'buy', '2025-08-05', 1, 1000);

        // Act
        $report = app(ShowTradeReviewAction::class)->execute();

        // Assert
        expect($report['years'][2025]['jp']['verdict'])->not->toBe('reference');
        expect($report['years'][2025]['us']['verdict'])->toBe('reference');
        expect($report['latest']['jp']['verdict'])->not->toBe('reference');
        expect($report['latest']['us']['verdict'])->toBe('reference');
    });
});

describe('3. 修正ディーツ法の資金の重みを約定日の曜日で付ける', function () {
    test('月曜の買付は重み1.0、水曜は0.6、金曜は0.2で、銘柄ごとに重みつきの資金の出入りが出る', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $a = rfHolding('1111');
        $b = rfHolding('2222');
        $c = rfHolding('3333');
        foreach ([$a, $b, $c] as $h) {
            rfWeekly($h, '2026-09-21', '2026-09-28', 100.0);
        }
        rfTrade($a, 'buy', '2026-09-21', 1, 100);  // Monday
        rfTrade($b, 'buy', '2026-09-23', 1, 100);  // Wednesday
        rfTrade($c, 'buy', '2026-09-25', 1, 100);  // Friday

        // Act
        $week = app(WeeklyPortfolioBuilder::class)->build()['2026-09-21'];

        // Assert
        expect($week->holdingWeightedFlowsJpy[$a->id])->toEqualWithDelta(100.0, 1e-9);
        expect($week->holdingWeightedFlowsJpy[$b->id])->toEqualWithDelta(60.0, 1e-9);
        expect($week->holdingWeightedFlowsJpy[$c->id])->toEqualWithDelta(20.0, 1e-9);
    });

    test('保有ゼロから月曜に買って週末に10%上がった週は、+20%ではなく+10%になる', function () {
        // Arrange: V0 = 0, Monday buy 1,000 (weight 1.0), worth 1,100 at the close
        $weeks = [
            '2026-09-14' => new PortfolioWeek('2026-09-14', 0.0, 0.0, [], [], 0.0),
            '2026-09-21' => new PortfolioWeek('2026-09-21', 1100.0, 1000.0, [1 => 10.0], [], 0.0,
                holdingValuesJpy: [1 => 1100.0], holdingFlowsJpy: [1 => 1000.0], holdingWeightedFlowsJpy: [1 => 1000.0]),
        ];

        // Act
        $p = app(StockPerformanceCalculator::class)->calculate($weeks, [1 => 'jp'], null, '2026-09-14', '2026-09-21', 1);

        // Assert
        expect($p->returnRate)->toEqualWithDelta(0.10, 1e-9);
    });

    test('重みつきの資金の出入りがない週（手作りの週）は、これまでどおり半分を分母に入れる', function () {
        // Arrange: 100 → 160 with +50 bought, no weighted flows given
        $weeks = [
            '2026-09-14' => new PortfolioWeek('2026-09-14', 100.0, 0.0, [], [], 0.0, holdingValuesJpy: [1 => 100.0]),
            '2026-09-21' => new PortfolioWeek('2026-09-21', 160.0, 50.0, [], [], 0.0, holdingValuesJpy: [1 => 160.0], holdingFlowsJpy: [1 => 50.0]),
        ];

        // Act / Assert: (160 − 100 − 50) / (100 + 25)
        expect(app(StockPerformanceCalculator::class)->calculate($weeks, [1 => 'jp'], null, '2026-09-14', '2026-09-21', 1)->returnRate)
            ->toEqualWithDelta(0.08, 1e-9);
    });
});
