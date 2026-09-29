<?php

namespace Tests\Feature\SignalOutcome;

use App\Models\Holding;
use App\Models\SignalOccurrence;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| signal_occurrences schema / model — Red phase Feature Test
| (UC-014 / F-014, CHG-0020 Cycle1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D3
|   - docs/architecture/data-model.md `signal_occurrences`
|   - docs/product/use-cases.md UC-014 基本フロー（記録）4
|       「同じ銘柄・種別・週の記録は1件のみ」
|
| Cycle1 only provides the table + App\Models\SignalOccurrence (no writes
| from the analysis pipeline yet). Contract assumed here:
|   - fillable: holding_id, source, signal_type, observed_week, snapshot_id, metrics
|   - metrics is cast to array (JSON column)
|   - UNIQUE(holding_id, source, signal_type, observed_week) is enforced at DB level
|
| Not yet implemented: model + migration. Expected Red: class not found.
|
*/

function socHolding(): Holding
{
    return Holding::create([
        'symbol_code' => '6758',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'ソニーグループ',
        'first_detected_at' => now(),
    ]);
}

test('シグナル発生記録の根拠値（metrics）はJSONとして保存され配列として読み戻せる', function () {
    // Arrange
    $holding = socHolding();
    $metrics = ['close' => 3200.5, 'rsi' => 72.3, 'peg' => null, 'ma75_trend_rising' => true];

    // Act
    $occurrence = SignalOccurrence::create([
        'holding_id' => $holding->id,
        'source' => 'take_profit',
        'signal_type' => 'rsi_overbought',
        'observed_week' => '2026-09-21',
        'snapshot_id' => null,
        'metrics' => $metrics,
    ]);

    // Assert
    $reloaded = SignalOccurrence::findOrFail($occurrence->id);
    // MySQL JSON columns normalize key order on storage, so compare values
    // regardless of key order (Gate4 re-approved 2026-09-27).
    expect($reloaded->metrics)->toEqual($metrics);
    $this->assertDatabaseHas('signal_occurrences', [
        'holding_id' => $holding->id,
        'source' => 'take_profit',
        'signal_type' => 'rsi_overbought',
        'observed_week' => '2026-09-21',
    ]);
});

test('metricsはnull（既存スナップショットからの移送分）でも保存できる', function () {
    $holding = socHolding();

    $occurrence = SignalOccurrence::create([
        'holding_id' => $holding->id,
        'source' => 'buy',
        'signal_type' => 'pullback',
        'observed_week' => '2026-09-14',
        'snapshot_id' => null,
        'metrics' => null,
    ]);

    expect(SignalOccurrence::findOrFail($occurrence->id)->metrics)->toBeNull();
});

test('同じ銘柄・発生元・種別・週のシグナル発生記録は重複して保存できない', function () {
    // Arrange
    $holding = socHolding();
    $attributes = [
        'holding_id' => $holding->id,
        'source' => 'watchlist_buy',
        'signal_type' => 'pullback',
        'observed_week' => '2026-09-21',
        'snapshot_id' => null,
        'metrics' => null,
    ];
    SignalOccurrence::create($attributes);

    // Act + Assert
    expect(fn () => SignalOccurrence::create($attributes))->toThrow(QueryException::class);
});

test('発生元または週が異なれば同じ銘柄・種別でも別の記録として保存できる', function () {
    $holding = socHolding();
    $base = [
        'holding_id' => $holding->id,
        'signal_type' => 'pullback',
        'snapshot_id' => null,
        'metrics' => null,
    ];

    SignalOccurrence::create([...$base, 'source' => 'buy', 'observed_week' => '2026-09-21']);
    SignalOccurrence::create([...$base, 'source' => 'watchlist_buy', 'observed_week' => '2026-09-21']);
    SignalOccurrence::create([...$base, 'source' => 'buy', 'observed_week' => '2026-09-28']);

    expect(SignalOccurrence::where('holding_id', $holding->id)->count())->toBe(3);
});
