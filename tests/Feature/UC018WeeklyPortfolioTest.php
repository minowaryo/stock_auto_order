<?php

namespace Tests\Feature;

use App\Actions\PriceTracking\TrackPriceHistoryAction;
use App\Models\Holding;
use App\Models\PriceTrackingTarget;
use App\Models\StockSplit;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\MarketData\PriceBackfillClientInterface;
use App\Services\MarketData\PriceHistory;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| UC-018 週ごとの株式部分の復元 — Red phase (CHG-0033 Cycle 7a)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-018 基本フロー1（株式部分・入出庫は時価で
|     資金の出入り・分割入庫は株数調整のみ）
|   - docs/product/trade-decision-effect-gate2-uc-draft.md 決定1
|     （V_t = 復元株数 × 週末終値〔米国株は同じ週のドル円で円換算〕、
|      F_t = 買付受渡 − 売却受渡、入庫・出庫は時価で F_t に含める）
|   - docs/adr/ADR-0024 D6 / PLAN.md（分割の取り直し待ちは算出不可）
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\TradeReview\WeeklyPortfolioBuilder::build(?string $market
|     = null): array<string, PortfolioWeek>, keyed by the Monday of the week,
|     ascending, from the week of the first trade to the last CONFIRMED week
|     (the week whose next Monday has started). $market 'jp' / 'us' limits
|     the holdings; null = both.
|   - App\Services\TradeReview\Support\PortfolioWeek (readonly): week,
|     valueJpy, flowJpy, quantities (holding_id => split-adjusted shares
|     held at the end of the week, only > 0), excluded (holding_id =>
|     reason), excludedEstimateJpy.
|   - Shares: a trade belongs to the week of its trade_date, using the same
|     WeekDateNormalizer as the weekly bars (trades are Monday-Friday only;
|     changed from a Sunday example at Gate 4, 2026-10-11). buy / tsumitate /
|     transfer_in add, sell / transfer_out subtract, summed over accounts.
|     A trade before a split (stock_splits.effective_date > trade_date) is
|     multiplied by the split ratio, because Yahoo closes are split-adjusted.
|     split_in rows are ignored (the split is taken from stock_splits).
|   - Value: shares × weekly_prices.close of that week; a US holding × the
|     'usdjpy' close of the same week.
|   - Flow (JPY, in the week of the trade): + buy / tsumitate, − sell, using
|     settlement_amount_jpy, else settlement_amount_usd × fx_rate (the CSV
|     rate). transfer_in / transfer_out have no settlement: + / − shares ×
|     that week's close (× usdjpy) — the market value.
|   - Excluded holdings are left out of valueJpy AND flowJpy for the week:
|       no_price        no close for that week
|       no_fx           US holding, no usdjpy close for that week
|       split_pending   (every week) a stock_splits row was created after
|                       COALESCE(full_history_fetched_at, created_at) of
|                       its price_tracking_targets row
|       split_incomplete (every week) price_tracking_targets.splits_incomplete
|     excludedEstimateJpy = Σ shares × the last close known up to that week
|     (× the last usdjpy known), or × the unit price of its latest trade up
|     to that week when no close is known at all.
|
| Expected Red: the builder and PortfolioWeek do not exist yet.
|
*/

function pwHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

/**
 * @param  array{jpy?: float, usd?: float, fx?: float, price?: float, account?: string}  $o
 */
function pwTrade(Holding $holding, string $kind, string $date, float $quantity, array $o = []): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market, 'trade_date' => $date,
        'account_type' => $o['account'] ?? 'specific', 'kind' => $kind, 'is_routine' => $kind === 'tsumitate',
        'quantity' => $quantity, 'unit_price' => $o['price'] ?? null,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy',
        'settlement_currency' => isset($o['usd']) ? 'usd' : (isset($o['jpy']) ? 'jpy' : null),
        'settlement_amount_jpy' => $o['jpy'] ?? null, 'settlement_amount_usd' => $o['usd'] ?? null,
        'fx_rate' => $o['fx'] ?? null,
        'content_hash' => str_pad((string) (7000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

/**
 * @param  array<string, float>  $closes  week Monday => close
 */
function pwPrices(Holding $holding, array $closes): void
{
    app(WeeklyPriceRecorder::class)->recordHolding($holding, array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 1],
        array_keys($closes), $closes,
    ));
}

/**
 * @param  array<string, float>  $closes  week Monday => USD/JPY close
 */
function pwUsdJpy(array $closes): void
{
    app(WeeklyPriceRecorder::class)->recordIndex('usdjpy', array_map(
        fn (string $week, float $close) => ['date' => $week, 'close' => $close, 'volume' => 0],
        array_keys($closes), $closes,
    ));
}

/**
 * @return array<string, PortfolioWeek>
 */
function pwBuild(?string $market = null): array
{
    return app(WeeklyPortfolioBuilder::class)->build($market);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00'); // last confirmed week: 2026-09-28
});

describe('UC-018 週ごとの株式部分: 株数・評価額・資金の出入り', function () {
    test('国内株の買付と売却から、各週末の株数・評価額（株数×週末終値）・資金の出入り（買付＋、売却−）が出る', function () {
        // Arrange
        $a = pwHolding('1111');
        pwPrices($a, ['2026-08-31' => 100, '2026-09-07' => 110, '2026-09-14' => 120, '2026-09-21' => 130, '2026-09-28' => 140]);
        pwTrade($a, 'buy', '2026-09-02', 100, ['jpy' => 10000, 'price' => 100]);
        pwTrade($a, 'sell', '2026-09-16', 40, ['jpy' => 4800, 'price' => 120]);

        // Act
        $weeks = pwBuild();

        // Assert: hand-computed
        expect(array_keys($weeks))->toBe(['2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28']);
        expect(array_map(fn (PortfolioWeek $w) => $w->quantities[$a->id] ?? 0.0, array_values($weeks)))->toBe([100.0, 100.0, 60.0, 60.0, 60.0]);
        expect(array_map(fn (PortfolioWeek $w) => $w->valueJpy, array_values($weeks)))->toBe([10000.0, 11000.0, 7200.0, 7800.0, 8400.0]);
        expect(array_map(fn (PortfolioWeek $w) => $w->flowJpy, array_values($weeks)))->toBe([10000.0, 0.0, -4800.0, 0.0, 0.0]);
    });

    test('米国株は同じ週のドル円で円換算し、ドル決済の受渡はCSVの為替レートで円にする', function () {
        // Arrange
        $us = pwHolding('NVDA', 'us');
        pwPrices($us, ['2026-09-14' => 10, '2026-09-21' => 11, '2026-09-28' => 12]);
        pwUsdJpy(['2026-09-14' => 150, '2026-09-21' => 148, '2026-09-28' => 146]);
        pwTrade($us, 'buy', '2026-09-15', 10, ['jpy' => 15000, 'price' => 10, 'fx' => 150]);
        pwTrade($us, 'buy', '2026-09-22', 5, ['usd' => 55, 'fx' => 147, 'price' => 11]);

        // Act
        $weeks = pwBuild();

        // Assert
        expect($weeks['2026-09-14']->valueJpy)->toEqualWithDelta(15000.0, 0.001);  // 10 × 10 × 150
        expect($weeks['2026-09-14']->flowJpy)->toEqualWithDelta(15000.0, 0.001);
        expect($weeks['2026-09-21']->valueJpy)->toEqualWithDelta(24420.0, 0.001);  // 15 × 11 × 148
        expect($weeks['2026-09-21']->flowJpy)->toEqualWithDelta(8085.0, 0.001);    // 55 × 147
        expect($weeks['2026-09-28']->valueJpy)->toEqualWithDelta(26280.0, 0.001);  // 15 × 12 × 146
    });

    test('入庫・出庫は受渡がないので、その週末の時価を資金の出入りにする', function () {
        // Arrange
        $b = pwHolding('2222');
        pwPrices($b, ['2026-09-14' => 200, '2026-09-21' => 210, '2026-09-28' => 220]);
        pwTrade($b, 'transfer_in', '2026-09-16', 50, ['price' => 180]);
        pwTrade($b, 'transfer_out', '2026-09-23', 20, ['price' => 210]);

        // Act
        $weeks = pwBuild();

        // Assert
        expect([$weeks['2026-09-14']->valueJpy, $weeks['2026-09-14']->flowJpy])->toBe([10000.0, 10000.0]); // 50 × 200
        expect([$weeks['2026-09-21']->valueJpy, $weeks['2026-09-21']->flowJpy])->toBe([6300.0, -4200.0]);  // 30 × 210, −20 × 210
        expect($weeks['2026-09-28']->flowJpy)->toBe(0.0);
    });

    test('積立は買付と同じく株数と資金の出入りに入り、口座区分の違う同じ銘柄は合算する', function () {
        // Arrange
        $c = pwHolding('3333');
        pwPrices($c, ['2026-09-21' => 100, '2026-09-28' => 100]);
        pwTrade($c, 'buy', '2026-09-22', 10, ['jpy' => 1000, 'price' => 100, 'account' => 'specific']);
        pwTrade($c, 'buy', '2026-09-23', 5, ['jpy' => 500, 'price' => 100, 'account' => 'nisa_growth']);
        pwTrade($c, 'tsumitate', '2026-09-24', 1, ['jpy' => 100, 'price' => 100]);

        // Act
        $week = pwBuild()['2026-09-21'];

        // Assert
        expect($week->quantities[$c->id])->toBe(16.0);
        expect([$week->valueJpy, $week->flowJpy])->toBe([1600.0, 1600.0]);
    });

    test('分割より前の売買の株数は分割比で揃え（終値が分割調整済みのため）、分割入庫の行は数えない', function () {
        // Arrange: a 2:1 split effective 2026-09-16; prices are already split-adjusted
        $d = pwHolding('4444');
        pwPrices($d, ['2026-08-31' => 50, '2026-09-07' => 50, '2026-09-14' => 50, '2026-09-21' => 50, '2026-09-28' => 50]);
        pwTrade($d, 'buy', '2026-09-02', 100, ['jpy' => 10000, 'price' => 100]);
        pwTrade($d, 'split_in', '2026-09-16', 100);
        pwTrade($d, 'buy', '2026-09-24', 50, ['jpy' => 2500, 'price' => 50]);
        StockSplit::create(['holding_id' => $d->id, 'effective_date' => '2026-09-16', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']);

        // Act
        $weeks = pwBuild();

        // Assert
        expect(array_map(fn (PortfolioWeek $w) => $w->quantities[$d->id], array_values($weeks)))->toBe([200.0, 200.0, 200.0, 250.0, 250.0]);
        expect($weeks['2026-09-14']->flowJpy)->toBe(0.0);
        expect($weeks['2026-08-31']->valueJpy)->toBe(10000.0); // 200 × 50: the same money as 100 × 100
    });

    test('週の最後の取引日（金曜）の売買はその週に入り、期間は最初の売買の週から確定した最新週まで', function () {
        // Arrange: prices exist for the running week 2026-10-05 too
        $e = pwHolding('5555');
        pwPrices($e, ['2026-08-31' => 10, '2026-09-07' => 10, '2026-09-14' => 10, '2026-09-21' => 10, '2026-09-28' => 10, '2026-10-05' => 10]);
        pwTrade($e, 'buy', '2026-09-04', 1, ['jpy' => 10, 'price' => 10]); // Friday (no trade falls on a weekend; Sunday is the next week, as for the weekly bars)

        // Act
        $weeks = pwBuild();

        // Assert
        expect(array_key_first($weeks))->toBe('2026-08-31');
        expect(array_key_last($weeks))->toBe('2026-09-28');
        expect($weeks['2026-08-31']->flowJpy)->toBe(10.0);
    });

    test('市場を指定すると、その市場の銘柄だけで出す', function () {
        // Arrange
        $jp = pwHolding('6666');
        $us = pwHolding('AAPL', 'us');
        pwPrices($jp, ['2026-09-28' => 100]);
        pwPrices($us, ['2026-09-28' => 10]);
        pwUsdJpy(['2026-09-28' => 150]);
        pwTrade($jp, 'buy', '2026-09-29', 1, ['jpy' => 100, 'price' => 100]);
        pwTrade($us, 'buy', '2026-09-29', 1, ['jpy' => 1500, 'price' => 10, 'fx' => 150]);

        // Act / Assert
        expect(pwBuild('jp')['2026-09-28']->valueJpy)->toBe(100.0);
        expect(pwBuild('us')['2026-09-28']->valueJpy)->toBe(1500.0);
        expect(pwBuild()['2026-09-28']->valueJpy)->toBe(1600.0);
    });
});

describe('UC-018 週ごとの株式部分: 算出できない銘柄', function () {
    test('終値がない週は、その銘柄を評価額・資金の出入りから外し、直近の終値での見積りを残す（他の銘柄はそのまま）', function () {
        // Arrange
        $gap = pwHolding('1111');
        $ok = pwHolding('2222');
        pwPrices($gap, ['2026-09-14' => 100, '2026-09-28' => 120]); // no close for 2026-09-21
        pwPrices($ok, ['2026-09-14' => 10, '2026-09-21' => 10, '2026-09-28' => 10]);
        pwTrade($gap, 'buy', '2026-09-15', 10, ['jpy' => 1000, 'price' => 100]);
        pwTrade($ok, 'buy', '2026-09-15', 5, ['jpy' => 50, 'price' => 10]);
        pwTrade($gap, 'buy', '2026-09-22', 5, ['jpy' => 550, 'price' => 110]); // in the week without a close

        // Act
        $week = pwBuild()['2026-09-21'];

        // Assert
        expect($week->excluded)->toBe([$gap->id => 'no_price']);
        expect([$week->valueJpy, $week->flowJpy])->toBe([50.0, 0.0]);
        expect($week->excludedEstimateJpy)->toBe(1500.0); // 15 × the last known close 100
    });

    test('米国株は、その週のドル円がなければ外す（no_fx）', function () {
        // Arrange
        $us = pwHolding('NVDA', 'us');
        pwPrices($us, ['2026-09-21' => 10, '2026-09-28' => 10]);
        pwUsdJpy(['2026-09-21' => 150]); // no usdjpy for 2026-09-28
        pwTrade($us, 'buy', '2026-09-22', 2, ['jpy' => 3000, 'price' => 10, 'fx' => 150]);

        // Act
        $week = pwBuild()['2026-09-28'];

        // Assert
        expect($week->excluded)->toBe([$us->id => 'no_fx']);
        expect($week->valueJpy)->toBe(0.0);
        expect($week->excludedEstimateJpy)->toBe(3000.0); // 2 × 10 × the last known usdjpy 150
    });

    test('分割の取り直し待ち・分割情報の欠損の銘柄は、全部の週で外す', function () {
        // Arrange
        $pending = pwHolding('1111');
        $incomplete = pwHolding('2222');
        $fine = pwHolding('3333');
        foreach ([$pending, $incomplete, $fine] as $h) {
            pwPrices($h, ['2026-09-21' => 10, '2026-09-28' => 10]);
            pwTrade($h, 'buy', '2026-09-22', 1, ['jpy' => 10, 'price' => 10]);
        }
        PriceTrackingTarget::create(['holding_id' => $pending->id, 'status' => 'completed', 'full_history_fetched_at' => '2026-10-01 00:00:00']);
        StockSplit::create(['holding_id' => $pending->id, 'effective_date' => '2026-10-02', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']); // created now (10-07): after the fetch
        PriceTrackingTarget::create(['holding_id' => $incomplete->id, 'status' => 'completed', 'splits_incomplete' => true, 'full_history_fetched_at' => '2026-10-07 10:00:00']);
        PriceTrackingTarget::create(['holding_id' => $fine->id, 'status' => 'completed', 'full_history_fetched_at' => '2026-10-07 10:00:00']);

        // Act
        $weeks = pwBuild();

        // Assert
        foreach ($weeks as $week) {
            expect($week->excluded)->toBe([$pending->id => 'split_pending', $incomplete->id => 'split_incomplete']);
        }
        expect([$weeks['2026-09-21']->valueJpy, $weeks['2026-09-21']->flowJpy])->toBe([10.0, 10.0]);
    });

    test('売買がなければ空の結果を返す', function () {
        expect(pwBuild())->toBe([]);
    });
});

describe('UC-018 確定した最新週（日曜の扱い、7aで発見した不具合の再発防止）', function () {
    test('日曜は、その日を含む週がまだ確定していないので、期間は前の週までになる', function () {
        // Arrange: Sunday 2026-10-11 ends the week of 2026-10-05, which is still running
        Carbon::setTestNow('2026-10-11 10:00:00');
        $h = pwHolding('1111');
        pwPrices($h, ['2026-09-28' => 10, '2026-10-05' => 11]);
        pwTrade($h, 'buy', '2026-09-29', 1, ['jpy' => 10, 'price' => 10]);

        // Act / Assert
        expect(array_key_last(pwBuild()))->toBe('2026-09-28');
    });

    test('price:track も日曜は前の週を確定した最新週とし、その週の確定後に保存済みの銘柄・指数を取得し直さない', function () {
        // Arrange: Sunday 2026-10-11; the week of 2026-09-28 was saved on Tuesday 2026-10-06 (after it was confirmed)
        Carbon::setTestNow('2026-10-06 10:00:00');
        $h = pwHolding('1111');
        pwTrade($h, 'sell', '2026-09-30', 1, ['jpy' => 10, 'price' => 10]); // tracked until 2027-03-29
        pwPrices($h, ['2026-09-28' => 10]);
        foreach (['nikkei225', 'sp500', 'usdjpy'] as $name) {
            app(WeeklyPriceRecorder::class)->recordIndex($name, [['date' => '2026-09-28', 'close' => 1.0, 'volume' => 0]]);
        }
        Carbon::setTestNow('2026-10-11 10:00:00');
        Sleep::fake();
        $client = new class implements PriceBackfillClientInterface
        {
            /** @var list<string> */
            public array $requests = [];

            public function fetchStock(string $market, string $symbolCode): PriceHistory
            {
                $this->requests[] = "{$market}:{$symbolCode}";

                return new PriceHistory(PriceHistory::OK, [['date' => '2026-09-28', 'close' => 10.0, 'volume' => 1]]);
            }

            public function fetchIndex(string $indexName): PriceHistory
            {
                $this->requests[] = "index:{$indexName}";

                return new PriceHistory(PriceHistory::OK, [['date' => '2026-09-28', 'close' => 1.0, 'volume' => 0]]);
            }
        };
        app()->instance(PriceBackfillClientInterface::class, $client);

        // Act
        $summary = app(TrackPriceHistoryAction::class)->execute();

        // Assert
        expect($client->requests)->toBe([]);
        expect($summary->savedAlready)->toBe(1);
    });
});

describe('UC-018 国内株の分割による入庫（実データ検証で判明、2026-10-11 本人承認）', function () {
    /*
     * Contract: the JP trade CSV books the shares a split adds as a plain 入庫
     * (transfer_in, no split marker), 1-3 days before Yahoo's split date. A JP
     * transfer_in is a split delivery — ignored for shares AND flow, like a
     * US split_in — when (1) a stock_splits row of the holding has
     * effective_date in (trade_date, trade_date + 7 days] and (2) its quantity
     * equals the same account's shares before it × (ratio − 1). Otherwise it
     * stays a real transfer at market value.
     */
    beforeEach(function () {
        $this->jp = pwHolding('7777');
        pwPrices($this->jp, ['2026-08-31' => 50, '2026-09-07' => 50, '2026-09-14' => 50, '2026-09-21' => 50, '2026-09-28' => 50]);
        pwTrade($this->jp, 'buy', '2026-09-02', 100, ['jpy' => 10000, 'price' => 100]);
    });

    test('分割の直前の入庫で、株数が分割で増えた分と一致するものは、株数にも資金の出入りにも入れない', function () {
        // Arrange: 2:1 split on 2026-09-16, the CSV books +100 shares on 2026-09-15
        pwTrade($this->jp, 'transfer_in', '2026-09-15', 100);
        StockSplit::create(['holding_id' => $this->jp->id, 'effective_date' => '2026-09-16', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']);

        // Act
        $weeks = pwBuild();

        // Assert: 100 shares bought before the split = 200 split-adjusted, nothing added by the delivery
        expect(array_map(fn (PortfolioWeek $w) => $w->quantities[$this->jp->id], array_values($weeks)))->toBe([200.0, 200.0, 200.0, 200.0, 200.0]);
        expect($weeks['2026-09-14']->flowJpy)->toBe(0.0);
    });

    test('分割の近くでも、株数が分割で増えた分と一致しない入庫は、本当の入庫として時価で扱う', function () {
        // Arrange: +30 shares are not the 100 a 2:1 split adds
        pwTrade($this->jp, 'transfer_in', '2026-09-15', 30);
        StockSplit::create(['holding_id' => $this->jp->id, 'effective_date' => '2026-09-16', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']);

        // Act
        $week = pwBuild()['2026-09-14'];

        // Assert: 200 + 30 × 2 split-adjusted; the 60 shares flow in at the week's close 50
        expect($week->quantities[$this->jp->id])->toBe(260.0);
        expect($week->flowJpy)->toBe(3000.0);
    });

    test('分割が7日より先なら、株数が一致しても本当の入庫として時価で扱う', function () {
        // Arrange: the split is 9 days after the transfer
        pwTrade($this->jp, 'transfer_in', '2026-09-15', 100);
        StockSplit::create(['holding_id' => $this->jp->id, 'effective_date' => '2026-09-24', 'ratio_numerator' => 2, 'ratio_denominator' => 1, 'source' => 'yahoo']);

        // Act
        $week = pwBuild()['2026-09-14'];

        // Assert: 200 + 100 × 2; the 200 shares flow in at 50
        expect($week->quantities[$this->jp->id])->toBe(400.0);
        expect($week->flowJpy)->toBe(10000.0);
    });
});
