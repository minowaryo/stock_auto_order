<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\IndicatorObservation;
use App\Models\PriceTrackingTarget;
use App\Models\StockSplit;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Models\WeeklyPrice;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\Support\TradeContext;
use App\Services\TradeReview\Support\TradeSituation;
use App\Services\TradeReview\TradeContextFinder;
use App\Services\TradeReview\TradeSituationClassifier;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-019 売買時の状況の分類 — Red phase (CHG-0033 Cycle B1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-019（2026-10-19 Gate 2承認）基本フロー1・2・4:
|     状況の出所（当時記録／事後再計算〔価格系〕）、区分の境目は既存シグナルの
|     閾値を使う、2026-10より前の売買は売買週の前週末までの株価だけで価格系
|     指標を事後再計算し、業績指標は再計算しない
|   - UC-018 業務ルール: 定型は状況別集計から外す
|   - 既存の閾値: BuySignalDeterminationService::RECENT_STRENGTH_THRESHOLD_RATE
|     (0.85)、MIN_RELATIVE_STRENGTH (-5.0)。指標の定義は
|     TechnicalIndicatorCalculator（week52_high = 直近52本の終値の最大値、
|     relative_strength_vs_market = 銘柄の13週リターン% − 指数の13週リターン%）
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\TradeReview\TradeSituationClassifier::classify(
|       array<string, PortfolioWeek> $weeks, array<int, TradeContext> $contexts
|     ): array<int, TradeSituation> keyed by trade id.
|   - Only buy / sell trades with is_routine = false are classified; routine
|     trades (tsumitate, or any trade flagged is_routine) are left out of the
|     result entirely.
|   - App\Services\TradeReview\Support\TradeSituation (readonly): tradeId;
|     highRatioBand 'near_high' | 'pullback' | 'unknown'; relativeStrengthBand
|     'strong' | 'weak' | 'unknown'; holdingBand 'none' | 'lt1' | '1to5' |
|     'gt5'; source 'recorded' | 'recomputed'.
|   - "前週末" = the week before the week the trade date falls in
|     (WeekDateNormalizer::weekStart). Closes of the trade week and later are
|     never used.
|   - Axis 1 (52週高値比) = the previous week's close ÷ the max close of the
|     52 weekly bars ending at the previous week. ≥ 0.85 near_high, < 0.85
|     pullback, fewer than 52 bars → unknown.
|   - Axis 2 (対市場の相対強度) = the stock's 13-week return % − the index's
|     13-week return % over the same weeks (previous week vs 13 weeks before
|     it; jp → nikkei225, us → sp500 in index_weekly_prices, local currency).
|     ≥ -5.0 strong, < -5.0 weak, any of the four closes missing → unknown.
|   - Source (decision for 仕様5, the partial case): each axis prefers the
|     value recorded at the time in TradeContext::$metrics — axis 1 needs
|     both 'close' and 'week52_high', axis 2 needs
|     'relative_strength_vs_market' — and otherwise is recomputed from the
|     weekly closes. source = 'recorded' only when BOTH axes came from the
|     record; if either axis was recomputed (or there was no record at all),
|     source = 'recomputed'.
|   - Holdings whose split-adjusted closes cannot be trusted (SplitData
|     'split_pending' or 'split_incomplete') get 'unknown' on axis 1 and
|     axis 2 when the axis would be recomputed. An axis taken from the
|     record at the time is NOT affected (the record was computed then, not
|     from today's closes), so a fully recorded trade stays 'recorded'.
|   - "The 52 weekly bars ending at the previous week" = the latest 52 bars
|     that exist on or before it (same as TechnicalIndicatorCalculator's
|     array_slice($closes, -52)), not the 52 calendar weeks: with a missing
|     week inside, the window reaches one calendar week further back.
|   - Axis 3 (保有状況) from TradeContext heldBefore / weightBefore: not held
|     → 'none'; weight < 1% → 'lt1'; 1% ≤ weight ≤ 5% → '1to5'; > 5% → 'gt5'.
|   - Earnings metrics (PER etc.) are never used.
|
| Expected Red: TradeSituationClassifier and TradeSituation do not exist yet.
|
*/

function tscHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

function tscTrade(Holding $holding, string $kind, string $date, ?bool $routine = null): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => 'specific', 'kind' => $kind, 'is_routine' => $routine ?? $kind === 'tsumitate',
        'quantity' => 10, 'unit_price' => 100,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy', 'settlement_currency' => 'jpy',
        'settlement_amount_jpy' => 1000, 'fx_rate' => $holding->market === 'us' ? 100.0 : null,
        'content_hash' => str_pad((string) (5000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * The Monday $n weeks before $week (negative $n = after).
 */
function tscWeek(string $week, int $n): string
{
    return Carbon::parse($week)->subWeeks($n)->toDateString();
}

/**
 * $count consecutive weekly closes of $close ending at $lastWeek, with
 * $overrides (week => close) on top.
 *
 * @param  array<string, float>  $overrides
 */
function tscCloses(Holding $holding, string $lastWeek, int $count, float $close, array $overrides = []): void
{
    $rows = [];

    for ($i = $count - 1; $i >= 0; $i--) {
        $rows[tscWeek($lastWeek, $i)] = $close;
    }

    foreach ($overrides as $week => $value) {
        $rows[$week] = $value;
    }

    ksort($rows);
    app(WeeklyPriceRecorder::class)->recordHolding($holding, array_map(
        fn (string $week, float $value) => ['date' => $week, 'close' => $value, 'volume' => 1],
        array_keys($rows),
        array_values($rows),
    ));
}

/**
 * @param  array<string, float>  $closes  week => close
 */
function tscIndex(string $name, array $closes): void
{
    ksort($closes);
    app(WeeklyPriceRecorder::class)->recordIndex($name, array_map(
        fn (string $week, float $value) => ['date' => $week, 'close' => $value],
        array_keys($closes),
        array_values($closes),
    ));
}

/**
 * @param  array<string, mixed>|null  $metrics
 */
function tscContext(TradeExecution $trade, ?array $metrics = null, bool $held = false, float $weight = 0.0): TradeContext
{
    return new TradeContext(
        tradeId: $trade->id,
        signals: [],
        metrics: $metrics,
        observedAt: $metrics !== null ? '2026-09-08 01:00:00' : null,
        known: $metrics !== null,
        heldBefore: $held,
        weightBefore: $weight,
    );
}

/**
 * @param  list<TradeContext>  $contexts
 * @return array<int, TradeSituation>
 */
function tscClassify(array $contexts): array
{
    $keyed = [];

    foreach ($contexts as $context) {
        $keyed[$context->tradeId] = $context;
    }

    return app(TradeSituationClassifier::class)->classify(app(WeeklyPortfolioBuilder::class)->build(), $keyed);
}

/**
 * @return array{0: string, 1: string, 2: string, 3: string}
 */
function tscBands(TradeSituation $s): array
{
    return [$s->highRatioBand, $s->relativeStrengthBand, $s->holdingBand, $s->source];
}

// Trade on Thursday 2026-09-10: trade week 2026-09-07, 前週末 = week 2026-08-31,
// 13 weeks before it = 2026-06-01, the 52-bar window = 2025-09-08 .. 2026-08-31.
const TSC_TRADE_DATE = '2026-09-10';
const TSC_TRADE_WEEK = '2026-09-07';
const TSC_PREV_WEEK = '2026-08-31';

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
});

describe('UC-019 状況別: 対象の売買', function () {
    test('買付・売却を分類し、定型（積立・定型フラグの売買）は結果に含めない', function () {
        // Arrange
        $a = tscHolding('1111');
        $buy = tscTrade($a, 'buy', TSC_TRADE_DATE);
        $sell = tscTrade($a, 'sell', TSC_TRADE_DATE);
        $tsumitate = tscTrade($a, 'tsumitate', TSC_TRADE_DATE);
        $routineBuy = tscTrade($a, 'buy', TSC_TRADE_DATE, routine: true);

        // Act
        $result = tscClassify([tscContext($buy), tscContext($sell), tscContext($tsumitate), tscContext($routineBuy)]);

        // Assert
        expect(array_keys($result))->toEqualCanonicalizing([$buy->id, $sell->id]);
        expect($result[$buy->id])->toBeInstanceOf(TradeSituation::class);
        expect([$result[$buy->id]->tradeId, $result[$sell->id]->tradeId])->toBe([$buy->id, $sell->id]);
    });
});

describe('UC-019 状況別: 52週高値比（事後再計算）', function () {
    test('前週末終値が直近52本の最高値のちょうど85％なら高値圏、それより低ければ押し目にする', function () {
        // Arrange: max of the 52 bars = 100 (at 2026-03-02); 前週末 85 → 0.85 / 84.99 → < 0.85
        $edge = tscHolding('1111');
        tscCloses($edge, TSC_PREV_WEEK, 52, 80.0, ['2026-03-02' => 100.0, TSC_PREV_WEEK => 85.0]);
        $below = tscHolding('2222');
        tscCloses($below, TSC_PREV_WEEK, 52, 80.0, ['2026-03-02' => 100.0, TSC_PREV_WEEK => 84.99]);
        $edgeTrade = tscTrade($edge, 'buy', TSC_TRADE_DATE);
        $belowTrade = tscTrade($below, 'buy', TSC_TRADE_DATE);

        // Act
        $result = tscClassify([tscContext($edgeTrade), tscContext($belowTrade)]);

        // Assert
        expect($result[$edgeTrade->id]->highRatioBand)->toBe('near_high');
        expect($result[$belowTrade->id]->highRatioBand)->toBe('pullback');
        expect($result[$edgeTrade->id]->source)->toBe('recomputed');
    });

    test('前週末を含む52本より前の高値は使わない', function () {
        // Arrange: a close of 200 on the 53rd week back (2025-09-01) is outside the window;
        // inside it the max is 100, 前週末 90 → 0.9
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 53, 80.0, [tscWeek(TSC_PREV_WEEK, 52) => 200.0, '2026-03-02' => 100.0, TSC_PREV_WEEK => 90.0]);
        $trade = tscTrade($a, 'sell', TSC_TRADE_DATE);

        // Act / Assert
        expect(tscClassify([tscContext($trade)])[$trade->id]->highRatioBand)->toBe('near_high');
    });

    test('週足に欠けた週があれば、前週末以前にある最新52本で52週高値比を計算する', function () {
        // Arrange: 53 calendar weeks 2025-09-01 .. 前週末 with 2026-01-05 missing → exactly 52 bars,
        // so the oldest bar 2025-09-01 (the 53rd week back by the calendar) is inside the window.
        // Its close 200 is the max → 前週末 90 / 200 = 0.45 → pullback.
        // (A 52-calendar-week window 2025-09-08 .. 前週末 would hold only 51 bars, max 100.)
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 53, 80.0, [tscWeek(TSC_PREV_WEEK, 52) => 200.0, '2026-03-02' => 100.0, TSC_PREV_WEEK => 90.0]);
        WeeklyPrice::query()->where('holding_id', $a->id)->where('week_date', '2026-01-05')->delete();
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        // precondition: the gap really exists, so exactly 52 bars are on or before 前週末
        expect(WeeklyPrice::query()->where('holding_id', $a->id)->where('week_date', '<=', TSC_PREV_WEEK)->count())->toBe(52);

        // Act
        $s = tscClassify([tscContext($trade)])[$trade->id];

        // Assert
        expect([$s->highRatioBand, $s->source])->toBe(['pullback', 'recomputed']);
    });

    test('前週末までの週足が52本に満たなければ52週高値比は不明にする', function () {
        // Arrange: 51 bars ending at 前週末
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 51, 100.0);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);

        // Act / Assert
        expect(tscClassify([tscContext($trade)])[$trade->id]->highRatioBand)->toBe('unknown');
    });
});

describe('UC-019 状況別: 対市場の相対強度（事後再計算）', function () {
    test('国内株は日経225との13週リターンの差がちょうど−5ポイントなら強い、それ未満なら弱いにする', function () {
        // Arrange: stocks 100 → 110 (+10%) from 2026-06-01 to 前週末
        $edge = tscHolding('1111');
        tscCloses($edge, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 110.0]);
        $edgeTrade = tscTrade($edge, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 115.0]); // +15% → 10 − 15 = −5.0

        // Act
        $edgeResult = tscClassify([tscContext($edgeTrade)])[$edgeTrade->id];

        // Arrange: the index +15.5% → 10 − 15.5 = −5.5
        tscIndex('nikkei225', [TSC_PREV_WEEK => 115.5]);

        // Act
        $weakResult = tscClassify([tscContext($edgeTrade)])[$edgeTrade->id];

        // Assert
        expect($edgeResult->relativeStrengthBand)->toBe('strong');
        expect($weakResult->relativeStrengthBand)->toBe('weak');
    });

    test('米国株はS&P500と比べ、日経225は使わない', function () {
        // Arrange: stock +10%; S&P500 +20% → −10 (weak); 日経225 flat would give +10 (strong)
        $u = tscHolding('NVDA', 'us');
        tscCloses($u, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 110.0]);
        $trade = tscTrade($u, 'buy', TSC_TRADE_DATE);
        tscIndex('sp500', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 120.0]);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);

        // Act / Assert
        expect(tscClassify([tscContext($trade)])[$trade->id]->relativeStrengthBand)->toBe('weak');
    });

    test('13週前または前週末の指数の終値が欠けていれば相対強度は不明にし、0として扱わない', function () {
        // Arrange: the stock has every close; 日経225 lacks 2026-06-01 (13 weeks before 前週末)
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 14) => 100.0, tscWeek(TSC_PREV_WEEK, 12) => 100.0, TSC_PREV_WEEK => 100.0]);

        // Act / Assert
        expect(tscClassify([tscContext($trade)])[$trade->id]->relativeStrengthBand)->toBe('unknown');
    });

    test('銘柄の13週前の終値が欠けていれば相対強度は不明にする', function () {
        // Arrange: the stock has only the 前週末 close
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 1, 100.0);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);

        // Act / Assert
        expect(tscClassify([tscContext($trade)])[$trade->id]->relativeStrengthBand)->toBe('unknown');
    });
});

describe('UC-019 状況別: 先読みの禁止', function () {
    test('売買週以降の銘柄・指数の終値を極端に変えても分類は変わらない', function () {
        // Arrange: 前週末 90 / max 100 → near_high; stock +10% vs 日経225 0% → +10 strong
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 90.0, tscWeek(TSC_PREV_WEEK, 13) => 81.81]);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);
        $before = tscBands(tscClassify([tscContext($trade)])[$trade->id]);

        // Arrange: the trade week and later — stock collapses then soars, the index soars
        tscCloses($a, tscWeek(TSC_TRADE_WEEK, -3), 4, 10000.0, [TSC_TRADE_WEEK => 1.0]);
        tscIndex('nikkei225', [TSC_TRADE_WEEK => 10000.0, tscWeek(TSC_TRADE_WEEK, -1) => 10000.0, tscWeek(TSC_TRADE_WEEK, -2) => 1.0, tscWeek(TSC_TRADE_WEEK, -3) => 1.0]);

        // Act
        $after = tscBands(tscClassify([tscContext($trade)])[$trade->id]);

        // Assert: the check is not vacuous — both axes were known before
        expect($before)->toBe(['near_high', 'strong', 'none', 'recomputed']);
        expect($after)->toBe($before);
    });
});

describe('UC-019 状況別: 状況の出所（当時記録／事後再計算）', function () {
    test('当時記録に終値・52週高値・対市場の相対強度があれば、その値で分類し出所を当時記録にする', function () {
        // Arrange: the weekly closes would say pullback / weak; the record says 85 / 100 = 0.85 and −5.0
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 50.0]);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 200.0]);
        $metrics = ['close' => 85.0, 'week52_high' => 100.0, 'relative_strength_vs_market' => -5.0];

        // Act / Assert
        expect(tscBands(tscClassify([tscContext($trade, $metrics)])[$trade->id]))->toBe(['near_high', 'strong', 'none', 'recorded']);
    });

    test('当時記録の値が押し目・弱いを示せば、週足に関係なくその値で分類する', function () {
        // Arrange: weekly closes say near_high / strong; the record says 84 / 100 and −5.01
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0);
        $trade = tscTrade($a, 'sell', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);
        $metrics = ['close' => 84.0, 'week52_high' => 100.0, 'relative_strength_vs_market' => -5.01];

        // Act / Assert
        expect(tscBands(tscClassify([tscContext($trade, $metrics)])[$trade->id]))->toBe(['pullback', 'weak', 'none', 'recorded']);
    });

    test('当時記録がない売買は週足から事後再計算し、出所を事後再計算にする', function () {
        // Arrange: no record; weekly closes: 前週末 90 / max 100, stock +10% vs 日経225 0%
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 90.0, tscWeek(TSC_PREV_WEEK, 13) => 81.81]);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);

        // Act / Assert
        expect(tscBands(tscClassify([tscContext($trade)])[$trade->id]))->toBe(['near_high', 'strong', 'none', 'recomputed']);
    });

    test('当時記録に価格系の値がなく週足もなければ、両軸とも不明・出所は事後再計算にする', function () {
        // Arrange: the record only has RSI; no weekly closes at all
        $a = tscHolding('1111');
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);

        // Act / Assert
        expect(tscBands(tscClassify([tscContext($trade, ['rsi' => 40.0])])[$trade->id]))->toBe(['unknown', 'unknown', 'none', 'recomputed']);
    });

    test('52週高値の記録がなければその軸だけ再計算し、相対強度は記録値を使い、出所は事後再計算にする', function () {
        // Arrange: record: close 95, week52_high null, rs −6.0 (weak);
        // weekly: 前週末 80 / max 100 → pullback, rs +10 (strong, not used)
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 80.0, tscWeek(TSC_PREV_WEEK, 13) => 72.72]);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);
        $metrics = ['close' => 95.0, 'week52_high' => null, 'relative_strength_vs_market' => -6.0];

        // Act / Assert
        expect(tscBands(tscClassify([tscContext($trade, $metrics)])[$trade->id]))->toBe(['pullback', 'weak', 'none', 'recomputed']);
    });

    test('相対強度の記録がなければその軸だけ再計算し、52週高値比は記録値を使い、出所は事後再計算にする', function () {
        // Arrange: record: 95 / 100 → near_high, rs null;
        // weekly: 前週末 50 / max 100 → pullback (not used), stock flat vs 日経225 +20% → −20 weak
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [TSC_PREV_WEEK => 50.0, tscWeek(TSC_PREV_WEEK, 13) => 50.0]);
        $trade = tscTrade($a, 'sell', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 120.0]);
        $metrics = ['close' => 95.0, 'week52_high' => 100.0, 'relative_strength_vs_market' => null];

        // Act / Assert
        expect(tscBands(tscClassify([tscContext($trade, $metrics)])[$trade->id]))->toBe(['near_high', 'weak', 'none', 'recomputed']);
    });

    test('業績系の指標（PER等）は分類に使わない', function () {
        // Arrange: two trades with the same price metrics and very different earnings metrics
        $a = tscHolding('1111');
        $cheap = tscTrade($a, 'buy', TSC_TRADE_DATE);
        $dear = tscTrade($a, 'buy', TSC_TRADE_DATE);
        $price = ['close' => 90.0, 'week52_high' => 100.0, 'relative_strength_vs_market' => 0.0];

        // Act
        $result = tscClassify([
            tscContext($cheap, [...$price, 'per' => 3.0, 'pbr' => 0.3, 'roe' => 30.0, 'peg_ratio' => 0.2]),
            tscContext($dear, [...$price, 'per' => 300.0, 'pbr' => 30.0, 'roe' => -5.0, 'peg_ratio' => 20.0]),
        ]);

        // Assert
        expect(tscBands($result[$cheap->id]))->toBe(['near_high', 'strong', 'none', 'recorded']);
        expect(tscBands($result[$dear->id]))->toBe(tscBands($result[$cheap->id]));
    });

    test('当時の指標の記録（TradeContextFinderの結果）をそのまま入力にして分類できる', function () {
        // Arrange: an observation 2 days before the trade day began (JST), weekly closes absent
        $a = tscHolding('1111');
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        IndicatorObservation::create([
            'holding_id' => $a->id, 'source' => 'holding_import', 'observed_at' => '2026-09-08 01:00:00',
            'metrics' => ['close' => 85, 'week52_high' => 100, 'relative_strength_vs_market' => -5, 'per' => 12.0],
        ]);
        $weeks = app(WeeklyPortfolioBuilder::class)->build();
        $contexts = app(TradeContextFinder::class)->find($weeks);

        // Act
        $result = app(TradeSituationClassifier::class)->classify($weeks, $contexts);

        // Assert
        expect(tscBands($result[$trade->id]))->toBe(['near_high', 'strong', 'none', 'recorded']);
    });
});

describe('UC-019 状況別: 分割の取り直し待ち・分割情報の欠損', function () {
    test('分割の取り直し待ちの銘柄は52週高値比・相対強度とも不明にする', function () {
        // Arrange: the weekly closes alone would give near_high / strong, but a split was
        // recorded after the last full fetch, so the closes are not split-adjusted yet
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [tscWeek(TSC_PREV_WEEK, 13) => 90.0]);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);
        PriceTrackingTarget::create(['holding_id' => $a->id, 'status' => 'completed', 'full_history_fetched_at' => '2026-10-01 00:00:00']);
        StockSplit::create(['holding_id' => $a->id, 'effective_date' => '2026-10-05', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']); // recorded now: after the fetch

        // Act
        $s = tscClassify([tscContext($trade, held: true, weight: 0.03)])[$trade->id];

        // Assert: the holding axis is still classified
        expect(tscBands($s))->toBe(['unknown', 'unknown', '1to5', 'recomputed']);
    });

    test('分割の取り直し待ちでも当時記録があれば、記録値で分類し出所を当時記録にする', function () {
        // Arrange: split pending as above; the weekly closes would say near_high / strong.
        // The record says 80 / 100 = 0.8 (pullback) and −6.0 (weak).
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [tscWeek(TSC_PREV_WEEK, 13) => 90.0]);
        $recorded = tscTrade($a, 'buy', TSC_TRADE_DATE);
        $unrecorded = tscTrade($a, 'sell', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);
        PriceTrackingTarget::create(['holding_id' => $a->id, 'status' => 'completed', 'full_history_fetched_at' => '2026-10-01 00:00:00']);
        StockSplit::create(['holding_id' => $a->id, 'effective_date' => '2026-10-05', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']); // recorded now: after the fetch
        $metrics = ['close' => 80.0, 'week52_high' => 100.0, 'relative_strength_vs_market' => -6.0];

        // Act
        $result = tscClassify([
            tscContext($recorded, $metrics, held: true, weight: 0.03),
            tscContext($unrecorded, held: true, weight: 0.03),
        ]);

        // Assert: the record is used; the same holding without a record stays unknown
        expect(tscBands($result[$recorded->id]))->toBe(['pullback', 'weak', '1to5', 'recorded']);
        expect(tscBands($result[$unrecorded->id]))->toBe(['unknown', 'unknown', '1to5', 'recomputed']);
    });

    test('分割情報の欠損の銘柄は、事後再計算では52週高値比・相対強度とも不明にする', function () {
        // Arrange: the weekly closes alone would give near_high / strong, but the split
        // history is known to be incomplete, so the closes cannot be trusted
        $a = tscHolding('1111');
        tscCloses($a, TSC_PREV_WEEK, 52, 100.0, [tscWeek(TSC_PREV_WEEK, 13) => 90.0]);
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);
        tscIndex('nikkei225', [tscWeek(TSC_PREV_WEEK, 13) => 100.0, TSC_PREV_WEEK => 100.0]);
        PriceTrackingTarget::create(['holding_id' => $a->id, 'status' => 'completed', 'splits_incomplete' => true, 'full_history_fetched_at' => '2026-10-07 10:00:00']);

        // Act
        $s = tscClassify([tscContext($trade, held: true, weight: 0.0501)])[$trade->id];

        // Assert: the holding axis is still classified
        expect(tscBands($s))->toBe(['unknown', 'unknown', 'gt5', 'recomputed']);
    });
});

describe('UC-019 状況別: 保有状況', function () {
    test('前週末の保有と同じ市場での割合で未保有・1％未満・1〜5％・5％超に分け、1％と5％ちょうどは1〜5％にする', function (bool $held, float $weight, string $band) {
        // Arrange
        $a = tscHolding('1111');
        $trade = tscTrade($a, 'buy', TSC_TRADE_DATE);

        // Act / Assert
        expect(tscClassify([tscContext($trade, held: $held, weight: $weight)])[$trade->id]->holdingBand)->toBe($band);
    })->with([
        '未保有' => [false, 0.0, 'none'],
        '0.99％' => [true, 0.0099, 'lt1'],
        '1％ちょうど' => [true, 0.01, '1to5'],
        '5％ちょうど' => [true, 0.05, '1to5'],
        '5.01％' => [true, 0.0501, 'gt5'],
    ]);
});
