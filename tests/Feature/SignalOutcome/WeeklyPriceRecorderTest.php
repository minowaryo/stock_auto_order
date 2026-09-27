<?php

namespace Tests\Feature\SignalOutcome;

use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\WeeklyPrice;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| WeeklyPriceRecorder — Red phase Feature Test (UC-014 / F-014, CHG-0020 Cycle1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D2
|   - docs/architecture/data-model.md `weekly_prices` / `index_weekly_prices`
|   - docs/product/use-cases.md UC-014 基本フロー（記録）2
|       「取得した週足を週次価格履歴として保存する（同じ週の値は最新の取得値で上書き）」
|     and エラーケース「週次価格履歴の保存に失敗（一部銘柄）」
|
| Contract proposed here (flag at Gate 4 if a different shape is preferred):
|   - resolved from the container: app(WeeklyPriceRecorder::class)
|   - recordHolding(Holding $holding, array $history): void
|   - recordIndex(string $indexName, array $history): void
|     $history = array<int, array{date: string, close: float, volume: int}>
|   - each row's date is normalized with WeekDateNormalizer::weekStart()
|   - when several rows land in the same week the FIRST one wins (the proper
|     weekly bar; later rows are Yahoo's trailing last-trading-day bar)
|   - UPSERT by (holding_id, week_date) / (index_name, week_date)
|   - never throws: any exception is logged as a warning and swallowed
|
| Not yet implemented: WeeklyPriceRecorder, WeeklyPrice / IndexWeeklyPrice
| models, weekly_prices / index_weekly_prices migrations. Every test below is
| expected to fail (Red) on the missing class.
|
*/

function wprHolding(string $code = '7203', string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => $market,
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄'.$code,
        'first_detected_at' => now(),
    ]);
}

function wprRecorder(): WeeklyPriceRecorder
{
    return app(WeeklyPriceRecorder::class);
}

test('保有銘柄の週足を週ごとに1行ずつ、週の月曜日付で保存する', function () {
    // Arrange: JP-style Sunday-stamped weekly bars
    $holding = wprHolding();
    $history = [
        ['date' => '2026-09-06', 'close' => 1000.0, 'volume' => 1000],
        ['date' => '2026-09-13', 'close' => 1010.5, 'volume' => 1100],
        ['date' => '2026-09-20', 'close' => 1020.25, 'volume' => 1200],
    ];

    // Act
    wprRecorder()->recordHolding($holding, $history);

    // Assert
    expect(WeeklyPrice::where('holding_id', $holding->id)->count())->toBe(3);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-07', 'close' => 1000.0, 'volume' => 1000]);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-14', 'close' => 1010.5, 'volume' => 1100]);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-21', 'close' => 1020.25, 'volume' => 1200]);
});

test('同じ週に末尾の最終取引日の足が付いていても2行目は作られず、先に出現した週足の終値・出来高が採用される', function () {
    // Arrange: Sunday bar 2026-09-20 + trailing Friday 2026-09-25 (same week)
    $holding = wprHolding();
    $history = [
        ['date' => '2026-09-13', 'close' => 1010.0, 'volume' => 1100],
        ['date' => '2026-09-20', 'close' => 1020.0, 'volume' => 1200],
        ['date' => '2026-09-25', 'close' => 1025.0, 'volume' => 300],
    ];

    // Act
    wprRecorder()->recordHolding($holding, $history);

    // Assert
    expect(WeeklyPrice::where('holding_id', $holding->id)->count())->toBe(2);
    expect(WeeklyPrice::where('holding_id', $holding->id)->where('week_date', '2026-09-21')->count())->toBe(1);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-21', 'close' => 1020.0, 'volume' => 1200]);
    $this->assertDatabaseMissing('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-25']);
});

test('同じ銘柄を再記録すると既存週の終値・出来高が上書きされ、行数は増えない（分割の遡及調整）', function () {
    // Arrange
    $holding = wprHolding();
    wprRecorder()->recordHolding($holding, [
        ['date' => '2026-09-14', 'close' => 4000.0, 'volume' => 100],
        ['date' => '2026-09-21', 'close' => 4100.0, 'volume' => 110],
    ]);

    // Act: the same weeks come back retro-adjusted (e.g. 1:4 split)
    wprRecorder()->recordHolding($holding, [
        ['date' => '2026-09-14', 'close' => 1000.0, 'volume' => 400],
        ['date' => '2026-09-21', 'close' => 1025.0, 'volume' => 440],
    ]);

    // Assert
    expect(WeeklyPrice::where('holding_id', $holding->id)->count())->toBe(2);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-14', 'close' => 1000.0, 'volume' => 400]);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $holding->id, 'week_date' => '2026-09-21', 'close' => 1025.0, 'volume' => 440]);
});

test('別銘柄の同じ週は別行として保存される', function () {
    $jp = wprHolding('7203', 'jp');
    $us = wprHolding('AAPL', 'us');

    wprRecorder()->recordHolding($jp, [['date' => '2026-09-20', 'close' => 3000.0, 'volume' => 10]]);
    wprRecorder()->recordHolding($us, [['date' => '2026-09-21', 'close' => 230.0, 'volume' => 20]]);

    expect(WeeklyPrice::count())->toBe(2);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $jp->id, 'week_date' => '2026-09-21', 'close' => 3000.0]);
    $this->assertDatabaseHas('weekly_prices', ['holding_id' => $us->id, 'week_date' => '2026-09-21', 'close' => 230.0]);
});

test('空の週足履歴では何も保存しない', function () {
    $holding = wprHolding();

    wprRecorder()->recordHolding($holding, []);

    expect(WeeklyPrice::count())->toBe(0);
});

test('日経平均の週足を週の月曜日付で指数週次価格履歴に保存する', function () {
    // Arrange: ^N225-style Sunday-stamped bars + trailing Friday bar
    $history = [
        ['date' => '2026-09-13', 'close' => 44000.0, 'volume' => 0],
        ['date' => '2026-09-20', 'close' => 45000.5, 'volume' => 0],
        ['date' => '2026-09-25', 'close' => 45100.0, 'volume' => 0],
    ];

    // Act
    wprRecorder()->recordIndex('nikkei225', $history);

    // Assert
    expect(IndexWeeklyPrice::where('index_name', 'nikkei225')->count())->toBe(2);
    $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'nikkei225', 'week_date' => '2026-09-14', 'close' => 44000.0]);
    $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'nikkei225', 'week_date' => '2026-09-21', 'close' => 45000.5]);
});

test('指数を再記録すると既存週が上書きされ行数は増えない', function () {
    wprRecorder()->recordIndex('sp500', [['date' => '2026-09-21', 'close' => 6600.0, 'volume' => 0]]);

    wprRecorder()->recordIndex('sp500', [['date' => '2026-09-21', 'close' => 6650.0, 'volume' => 0]]);

    expect(IndexWeeklyPrice::where('index_name', 'sp500')->count())->toBe(1);
    $this->assertDatabaseHas('index_weekly_prices', ['index_name' => 'sp500', 'week_date' => '2026-09-21', 'close' => 6650.0]);
});

test('記録中に例外が起きても例外を投げずに警告ログを残す（UC-014エラーケース）', function () {
    // Arrange: an unparsable date makes normalization fail inside the recorder
    Log::spy();
    $holding = wprHolding();

    // Act
    wprRecorder()->recordHolding($holding, [
        ['date' => 'not-a-date', 'close' => 1000.0, 'volume' => 100],
    ]);

    // Assert: reaching this line means no exception escaped
    Log::shouldHaveReceived('warning')->atLeast()->once();
});
