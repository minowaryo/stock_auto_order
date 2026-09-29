<?php

namespace Tests\Feature\SignalOutcome;

use App\Models\Holding;
use App\Models\ImportBatch;
use App\Models\SignalOccurrence;
use App\Models\Snapshot;
use App\Services\SignalOutcome\SignalOccurrenceRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| SignalOccurrenceRecorder — Red phase Feature Test (UC-014 / F-014, CHG-0020 Cycle2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D3
|   - docs/architecture/data-model.md `signal_occurrences`
|   - docs/product/use-cases.md UC-014 基本フロー（記録）4
|       「出たシグナルごとに、発生週・シグナル種別・発生時点の判定根拠値を
|        シグナル発生記録として追記する（同じ銘柄・種別・週の記録は1件のみ）」
|     and エラーケース「記録の失敗で本来の分析処理を止めない」
|
| Contract proposed here (flag at Gate 4 if a different shape is preferred):
|   - resolved from the container: app(SignalOccurrenceRecorder::class)
|   - record(Holding $holding, string $source, array $signalTypes,
|            string $observedWeek, ?int $snapshotId, ?array $metrics): void
|   - one row per signal type; UNIQUE(holding_id, source, signal_type,
|     observed_week) → re-recording the same week is a no-op (first record
|     and its metrics are kept, insert-or-ignore / firstOrCreate semantics)
|   - empty $signalTypes → nothing is written
|   - never throws: any exception is logged as a warning (context:
|     holding_id, source, exception message) and swallowed
|
| DB failure simulation: a DB::listen() listener that throws for any query
| touching signal_occurrences. It fires from inside the connection after the
| statement runs, so it surfaces from the recorder's own DB call regardless
| of whether it uses Eloquent firstOrCreate or insertOrIgnore (INSERT IGNORE
| would swallow constraint/length errors, so a data-level trigger is not
| reliable). No Schema DDL (on MySQL it would implicitly commit the
| RefreshDatabase transaction).
|
| Not yet implemented: App\Services\SignalOutcome\SignalOccurrenceRecorder.
| Expected Red: "Target class [App\Services\SignalOutcome\SignalOccurrenceRecorder] does not exist."
|
*/

function sorHolding(string $code = '6758'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄'.$code,
        'first_detected_at' => now(),
    ]);
}

function sorSnapshot(): Snapshot
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

    return Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => now(),
    ]);
}

function sorRecorder(): SignalOccurrenceRecorder
{
    return app(SignalOccurrenceRecorder::class);
}

test('出たシグナル種別ごとに1行ずつ、発生元・発生週・スナップショット・判定根拠値つきでシグナル発生記録を追記する', function () {
    // Arrange
    $holding = sorHolding();
    $snapshot = sorSnapshot();
    $metrics = ['close' => 3200.5, 'rsi' => 72.3, 'per' => null, 'ma75_trend_rising' => true];

    // Act
    sorRecorder()->record($holding, 'take_profit', ['rsi_reversal', 'bollinger_overheat'], '2026-09-21', $snapshot->id, $metrics);

    // Assert
    $rows = SignalOccurrence::where('holding_id', $holding->id)->orderBy('signal_type')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('signal_type')->all())->toBe(['bollinger_overheat', 'rsi_reversal']);

    foreach ($rows as $row) {
        expect($row->source)->toBe('take_profit');
        expect($row->observed_week->toDateString())->toBe('2026-09-21');
        expect($row->snapshot_id)->toBe($snapshot->id);
        expect($row->metrics)->toEqualCanonicalizing($metrics);
        expect($row->created_at)->not->toBeNull();
    }
});

test('ウォッチリストの押し目買い（watchlist_buy）はスナップショットなし（snapshot_id=null）で記録される', function () {
    // Arrange
    $holding = sorHolding();

    // Act
    sorRecorder()->record($holding, 'watchlist_buy', ['peg_undervalued'], '2026-09-21', null, ['close' => 1050.0]);

    // Assert
    $this->assertDatabaseHas('signal_occurrences', [
        'holding_id' => $holding->id,
        'source' => 'watchlist_buy',
        'signal_type' => 'peg_undervalued',
        'observed_week' => '2026-09-21',
        'snapshot_id' => null,
    ]);
});

test('同じ銘柄・発生元・種別・週を再度記録しても例外にならず重複せず、最初の記録の根拠値が保持される', function () {
    // Arrange
    $holding = sorHolding();
    $snapshot = sorSnapshot();
    sorRecorder()->record($holding, 'buy', ['rsi_oversold_rebound'], '2026-09-21', $snapshot->id, ['close' => 95.0]);

    // Act (reaching the assertions means no exception escaped)
    sorRecorder()->record($holding, 'buy', ['rsi_oversold_rebound', 'macd_golden_cross'], '2026-09-21', $snapshot->id, ['close' => 999.0]);

    // Assert
    expect(SignalOccurrence::where('holding_id', $holding->id)->count())->toBe(2);
    $first = SignalOccurrence::where('holding_id', $holding->id)->where('signal_type', 'rsi_oversold_rebound')->sole();
    expect($first->metrics)->toBe(['close' => 95.0]);
    $added = SignalOccurrence::where('holding_id', $holding->id)->where('signal_type', 'macd_golden_cross')->sole();
    expect($added->metrics)->toBe(['close' => 999.0]);
});

test('同じ種別でも週が違えば別の発生として記録される', function () {
    // Arrange
    $holding = sorHolding();

    // Act
    sorRecorder()->record($holding, 'buy', ['rsi_oversold_rebound'], '2026-09-14', null, null);
    sorRecorder()->record($holding, 'buy', ['rsi_oversold_rebound'], '2026-09-21', null, null);

    // Assert
    expect(SignalOccurrence::where('holding_id', $holding->id)->pluck('observed_week')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-09-14', '2026-09-21']);
});

test('同じ銘柄・種別・週でも発生元が違えば（buy と watchlist_buy）別の発生として記録される', function () {
    // Arrange
    $holding = sorHolding();

    // Act
    sorRecorder()->record($holding, 'buy', ['peg_undervalued'], '2026-09-21', null, null);
    sorRecorder()->record($holding, 'watchlist_buy', ['peg_undervalued'], '2026-09-21', null, null);

    // Assert
    expect(SignalOccurrence::where('holding_id', $holding->id)->pluck('source')->sort()->values()->all())
        ->toBe(['buy', 'watchlist_buy']);
});

test('出たシグナルが0件（空配列）のときは何も記録しない', function () {
    // Arrange
    $holding = sorHolding();

    // Act
    sorRecorder()->record($holding, 'take_profit', [], '2026-09-21', null, ['close' => 100.0]);

    // Assert
    expect(SignalOccurrence::count())->toBe(0);
});

test('根拠値（metrics）がnullでも記録できる（既存スナップショットからの移送と同じ形）', function () {
    // Arrange
    $holding = sorHolding();

    // Act
    sorRecorder()->record($holding, 'take_profit', ['macd_dead_cross'], '2026-09-21', null, null);

    // Assert
    $row = SignalOccurrence::where('holding_id', $holding->id)->sole();
    expect($row->metrics)->toBeNull();
});

test('記録時にDBエラーが起きても例外を投げず、銘柄IDと発生元をログに警告として残す（UC-014エラーケース）', function () {
    // Arrange
    Log::spy();
    $holding = sorHolding();
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'signal_occurrences')) {
            throw new RuntimeException('simulated signal_occurrences DB failure');
        }
    });

    // Act (reaching the assertions means no exception escaped)
    sorRecorder()->record($holding, 'buy', ['rsi_oversold_rebound'], '2026-09-21', null, ['close' => 95.0]);
    $armed = false;

    // Assert
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => ($context['holding_id'] ?? null) === $holding->id
            && ($context['source'] ?? null) === 'buy'
            && ! array_key_exists('metrics', $context))
        ->atLeast()->once();
});
