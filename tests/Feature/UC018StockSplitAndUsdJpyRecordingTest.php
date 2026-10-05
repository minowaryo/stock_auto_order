<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\StockSplit;
use App\Services\MarketData\PriceHistory;
use App\Services\PriceTracking\StockSplitRecorder;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| UC-018 株式分割とドル円の保存 — Red phase (CHG-0033 Cycle 6b)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0027-trade-history-storage.md D5（usdjpy）・D6（stock_splits）
|   - docs/adr/ADR-0024-trade-and-signal-price-tracking.md D5
|   - docs/architecture/data-model.md `stock_splits` / `index_weekly_prices`
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\PriceTracking\StockSplitRecorder::record(Holding,
|     PriceHistory): void. Upserts the history's splits into stock_splits
|     keyed by (holding_id, effective_date): ratio_numerator /
|     ratio_denominator, source 'yahoo'. Yahoo is the source of truth: a
|     corrected ratio on the same date overwrites the old one. A split that
|     is missing from a newer response is NOT deleted. Never throws (a DB
|     failure is logged with holding_id only and the caller carries on).
|   - index_weekly_prices.index_name accepts 'usdjpy' (the enum gets a
|     value appended, like 'sox' in CHG-0026; existing rows are untouched).
|     The existing WeeklyPriceRecorder::recordIndex('usdjpy', rows) is used
|     as is: UPSERT by (index_name, week_date), close only.
|
| Expected Red: the stock_splits table, the model, the recorder and the
| 'usdjpy' enum value do not exist yet.
|
*/

function spHolding(string $code = '9984'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

/**
 * @param  list<array{date: string, numerator: int, denominator: int}>  $splits
 */
function spHistory(array $splits): PriceHistory
{
    return new PriceHistory(PriceHistory::OK, [['date' => '2026-09-28', 'close' => 100.0, 'volume' => 1]], $splits);
}

describe('UC-018 株式分割の保存（StockSplitRecorder）', function () {
    test('取得した分割が、効力発生日・分子・分母・取得元つきで保存される', function () {
        // Arrange
        $holding = spHolding();

        // Act
        app(StockSplitRecorder::class)->record($holding, spHistory([
            ['date' => '2019-06-25', 'numerator' => 2, 'denominator' => 1],
            ['date' => '2025-12-29', 'numerator' => 4, 'denominator' => 1],
        ]));

        // Assert
        $rows = StockSplit::where('holding_id', $holding->id)->orderBy('effective_date')->get();
        expect($rows)->toHaveCount(2);
        expect($rows[0]->effective_date->toDateString())->toBe('2019-06-25');
        expect([$rows[0]->ratio_numerator, $rows[0]->ratio_denominator, $rows[0]->source])->toBe([2, 1, 'yahoo']);
        expect([$rows[1]->effective_date->toDateString(), $rows[1]->ratio_numerator])->toBe(['2025-12-29', 4]);
    });

    test('同じ分割を何度保存しても行は増えず、同じ日付の分割比が訂正されたら上書きされる', function () {
        // Arrange
        $holding = spHolding();
        $recorder = app(StockSplitRecorder::class);
        $recorder->record($holding, spHistory([['date' => '2025-12-29', 'numerator' => 4, 'denominator' => 1]]));
        $recorder->record($holding, spHistory([['date' => '2025-12-29', 'numerator' => 4, 'denominator' => 1]]));

        // Act: Yahoo later reports a corrected ratio for the same date
        $recorder->record($holding, spHistory([['date' => '2025-12-29', 'numerator' => 5, 'denominator' => 1]]));

        // Assert
        $row = StockSplit::where('holding_id', $holding->id)->sole();
        expect($row->ratio_numerator)->toBe(5);
    });

    test('新しい応答に出てこない過去の分割は、削除されない', function () {
        // Arrange
        $holding = spHolding();
        $recorder = app(StockSplitRecorder::class);
        $recorder->record($holding, spHistory([['date' => '2019-06-25', 'numerator' => 2, 'denominator' => 1]]));

        // Act
        $recorder->record($holding, spHistory([['date' => '2025-12-29', 'numerator' => 4, 'denominator' => 1]]));

        // Assert
        expect(StockSplit::where('holding_id', $holding->id)->count())->toBe(2);
    });

    test('分割のない銘柄・取得に失敗した結果では、何も保存せず、既存の分割も消さない', function () {
        // Arrange
        $holding = spHolding();
        $recorder = app(StockSplitRecorder::class);
        $recorder->record($holding, spHistory([['date' => '2019-06-25', 'numerator' => 2, 'denominator' => 1]]));

        // Act
        $recorder->record($holding, spHistory([]));
        $recorder->record($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 500'));

        // Assert
        expect(StockSplit::where('holding_id', $holding->id)->count())->toBe(1);
    });

    test('銘柄ごとに別の分割として保存され、同じ日付でも他の銘柄とは混ざらない', function () {
        // Arrange
        $a = spHolding('1111');
        $b = spHolding('2222');

        // Act
        app(StockSplitRecorder::class)->record($a, spHistory([['date' => '2025-12-29', 'numerator' => 2, 'denominator' => 1]]));
        app(StockSplitRecorder::class)->record($b, spHistory([['date' => '2025-12-29', 'numerator' => 4, 'denominator' => 1]]));

        // Assert
        expect(StockSplit::where('holding_id', $a->id)->sole()->ratio_numerator)->toBe(2);
        expect(StockSplit::where('holding_id', $b->id)->sole()->ratio_numerator)->toBe(4);
    });

    test('保存がDBエラーで失敗しても例外を投げず、ログには銘柄IDだけの警告が残る', function () {
        // Arrange
        Log::spy();
        $holding = spHolding();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'stock_splits')) {
                throw new RuntimeException('simulated stock_splits DB failure');
            }
        });

        // Act (reaching the assertions means nothing escaped). The listener throws after the
        // statement ran, so the row itself may exist; what matters is the swallowed error + the log.
        app(StockSplitRecorder::class)->record($holding, spHistory([['date' => '2025-12-29', 'numerator' => 4, 'denominator' => 1]]));
        $armed = false;

        // Assert
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['holding_id'] ?? null) === $holding->id)
            ->atLeast()->once();
    });
});

describe('UC-018 ドル円の週足の保存（index_weekly_prices の usdjpy）', function () {
    test('ドル円の週足が、既存の指数と同じ保存処理で保存され、再保存しても行は増えず値が更新される', function () {
        // Arrange
        $recorder = app(WeeklyPriceRecorder::class);
        $rows = [
            ['date' => '2026-09-21', 'close' => 147.25, 'volume' => 0],
            ['date' => '2026-09-28', 'close' => 148.5, 'volume' => 0],
        ];

        // Act
        $recorder->recordIndex('usdjpy', $rows);
        $recorder->recordIndex('usdjpy', [['date' => '2026-09-28', 'close' => 149.0, 'volume' => 0]]);

        // Assert
        $saved = IndexWeeklyPrice::where('index_name', 'usdjpy')->orderBy('week_date')->get();
        expect($saved)->toHaveCount(2);
        expect($saved[0]->week_date->toDateString())->toBe('2026-09-21');
        expect((float) $saved[0]->close)->toBe(147.25);
        expect((float) $saved[1]->close)->toBe(149.0);
    });

    test('ドル円を追加しても、日経225・S&P500・SOXの保存と、他の指数の行には影響しない', function () {
        // Arrange
        $recorder = app(WeeklyPriceRecorder::class);
        $row = [['date' => '2026-09-28', 'close' => 100.0, 'volume' => 0]];

        // Act
        foreach (['nikkei225', 'sp500', 'sox', 'usdjpy'] as $name) {
            $recorder->recordIndex($name, $row);
        }

        // Assert
        expect(IndexWeeklyPrice::count())->toBe(4);
        expect(IndexWeeklyPrice::whereIn('index_name', ['nikkei225', 'sp500', 'sox'])->count())->toBe(3);
    });
});
