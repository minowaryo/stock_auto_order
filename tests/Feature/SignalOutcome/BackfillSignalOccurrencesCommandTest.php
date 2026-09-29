<?php

namespace Tests\Feature\SignalOutcome;

use App\Models\BuySignal;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\SignalOccurrence;
use App\Models\Snapshot;
use App\Models\WatchlistBuySignal;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| signal-outcomes:backfill — Red phase Feature Test (UC-014 / F-014, CHG-0020 Cycle2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D6
|   - docs/architecture/data-model.md `signal_occurrences`（履歴ログ・移送分は metrics=null）
|   - docs/product/use-cases.md UC-014 業務ルール
|       「既存6スナップショットのシグナルは、本機能の導入時に一度だけ発生記録へ移送する」
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - command `signal-outcomes:backfill`
|     (App\Console\Commands\BackfillSignalOccurrencesCommand)
|   - every snapshot: Signal rows → source take_profit, BuySignal rows →
|     source buy; holding_id via holding_snapshots; snapshot_id set; metrics null
|   - observed_week = Monday of the ISO week containing snapshots.created_at
|     converted to Asia/Manila (app timezone is UTC; the user's display/date-judgment timezone, 2026-09-27)
|   - current watchlist_buy_signals → source watchlist_buy, snapshot_id null,
|     observed_week from determined_at (same rule)
|   - idempotent; never overwrites occurrences already recorded live
|   - exits 0 and prints a summary line
|
| Not yet implemented: the command. Expected Red:
| "The command "signal-outcomes:backfill" does not exist."
|
*/

function bsoHolding(string $code): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄'.$code,
        'first_detected_at' => now(),
    ]);
}

/**
 * Snapshot whose created_at is forced to $createdAtUtc (stored as UTC, the app timezone).
 */
function bsoSnapshot(string $createdAtUtc): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => $createdAtUtc,
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $createdAtUtc,
    ]);

    DB::table('snapshots')->where('id', $snapshot->id)->update(['created_at' => $createdAtUtc]);

    return $snapshot->refresh();
}

function bsoHoldingSnapshot(Snapshot $snapshot, Holding $holding): HoldingSnapshot
{
    return HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 100,
        'current_price' => 130,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 300,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
}

function bsoSignal(HoldingSnapshot $holdingSnapshot, string $type): void
{
    Signal::create(['holding_snapshot_id' => $holdingSnapshot->id, 'signal_type' => $type, 'reason_summary' => '移送テスト']);
}

function bsoBuySignal(HoldingSnapshot $holdingSnapshot, string $type): void
{
    BuySignal::create(['holding_snapshot_id' => $holdingSnapshot->id, 'signal_type' => $type, 'reason_summary' => '移送テスト']);
}

test('既存スナップショットの利確シグナルはtake_profit、買い増しシグナルはbuyとして、スナップショットIDつき・根拠値なしで移送される', function () {
    // Arrange: two weekly snapshots of the same holding
    $holding = bsoHolding('7203');
    $older = bsoSnapshot('2026-08-28 12:38:26'); // Fri 20:38 Manila → week 2026-08-24
    $newer = bsoSnapshot('2026-09-20 09:12:51'); // Sun 17:12 Manila → week 2026-09-14
    $olderHs = bsoHoldingSnapshot($older, $holding);
    $newerHs = bsoHoldingSnapshot($newer, $holding);
    bsoSignal($olderHs, 'rsi_reversal');
    bsoSignal($newerHs, 'rsi_reversal');
    bsoSignal($newerHs, 'bollinger_overheat');
    bsoBuySignal($newerHs, 'peg_undervalued');

    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    expect(SignalOccurrence::count())->toBe(4);
    $this->assertDatabaseHas('signal_occurrences', ['holding_id' => $holding->id, 'source' => 'take_profit', 'signal_type' => 'rsi_reversal', 'observed_week' => '2026-08-24', 'snapshot_id' => $older->id, 'metrics' => null]);
    $this->assertDatabaseHas('signal_occurrences', ['holding_id' => $holding->id, 'source' => 'take_profit', 'signal_type' => 'rsi_reversal', 'observed_week' => '2026-09-14', 'snapshot_id' => $newer->id, 'metrics' => null]);
    $this->assertDatabaseHas('signal_occurrences', ['holding_id' => $holding->id, 'source' => 'take_profit', 'signal_type' => 'bollinger_overheat', 'observed_week' => '2026-09-14', 'snapshot_id' => $newer->id, 'metrics' => null]);
    $this->assertDatabaseHas('signal_occurrences', ['holding_id' => $holding->id, 'source' => 'buy', 'signal_type' => 'peg_undervalued', 'observed_week' => '2026-09-14', 'snapshot_id' => $newer->id, 'metrics' => null]);
});

test('発生週はスナップショット作成日時をマニラ時間に換算した週の月曜日になる', function (string $createdAtUtc, string $expectedWeek) {
    // Arrange
    $holding = bsoHolding('7203');
    $snapshot = bsoSnapshot($createdAtUtc);
    bsoSignal(bsoHoldingSnapshot($snapshot, $holding), 'macd_dead_cross');

    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    $occurrence = SignalOccurrence::where('holding_id', $holding->id)->sole();
    expect($occurrence->observed_week->toDateString())->toBe($expectedWeek);
})->with([
    '日曜夕方（マニラ時間）の取込は当週月曜' => ['2026-09-20 09:12:51', '2026-09-14'],
    '金曜夜（マニラ時間）の取込は当週月曜' => ['2026-08-28 12:38:26', '2026-08-24'],
    'UTCでは日曜だがマニラ時間では月曜00:00の取込は翌週月曜' => ['2026-09-20 16:00:00', '2026-09-21'],
    '日本時間では月曜00:30だがマニラ時間ではまだ日曜23:30の取込は当週月曜（マニラ時間で判定する）' => ['2026-09-20 15:30:00', '2026-09-14'],
]);

// CHG-0024 / ADR-0018 D4: the date-judgment timezone is config('app.display_timezone'),
// not a hard-coded Asia/Manila. Expected Red: observed_week stays 2026-09-14 (Manila).
test('発生週の判定は設定値app.display_timezoneのタイムゾーンで行う（Asia/Tokyoに変えると日本時間の週になる）', function () {
    // Arrange: Sun 23:30 Manila = Mon 00:30 JST
    config(['app.display_timezone' => 'Asia/Tokyo']);
    $holding = bsoHolding('7203');
    $snapshot = bsoSnapshot('2026-09-20 15:30:00');
    bsoSignal(bsoHoldingSnapshot($snapshot, $holding), 'macd_dead_cross');

    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    $occurrence = SignalOccurrence::where('holding_id', $holding->id)->sole();
    expect($occurrence->observed_week->toDateString())->toBe('2026-09-21');
});

test('現在のウォッチリスト押し目買いシグナルはwatchlist_buyとして、スナップショットなし・判定日時の週で移送される', function () {
    // Arrange
    $holding = bsoHolding('8888');
    WatchlistBuySignal::create([
        'holding_id' => $holding->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => '移送テスト',
        'determined_at' => '2026-09-20 16:00:00', // Mon 00:00 Manila → week 2026-09-21
    ]);

    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('signal_occurrences', [
        'holding_id' => $holding->id,
        'source' => 'watchlist_buy',
        'signal_type' => 'rsi_oversold_rebound',
        'observed_week' => '2026-09-21',
        'snapshot_id' => null,
        'metrics' => null,
    ]);
});

test('移送コマンドを2回実行しても発生記録は重複しない', function () {
    // Arrange
    $holding = bsoHolding('7203');
    $hs = bsoHoldingSnapshot(bsoSnapshot('2026-09-20 09:12:51'), $holding);
    bsoSignal($hs, 'rsi_reversal');
    bsoBuySignal($hs, 'macd_golden_cross');
    WatchlistBuySignal::create([
        'holding_id' => bsoHolding('8888')->id,
        'signal_type' => 'peg_undervalued',
        'reason_summary' => '移送テスト',
        'determined_at' => '2026-09-20 09:00:00',
    ]);
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();
    expect(SignalOccurrence::count())->toBe(3);

    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    expect(SignalOccurrence::count())->toBe(3);
});

test('分析処理で既に記録済みの発生（根拠値あり）は移送で上書きされず重複もしない', function () {
    // Arrange
    $holding = bsoHolding('7203');
    $snapshot = bsoSnapshot('2026-09-20 09:12:51'); // → week 2026-09-14
    bsoSignal(bsoHoldingSnapshot($snapshot, $holding), 'bollinger_overheat');
    SignalOccurrence::create([
        'holding_id' => $holding->id,
        'source' => 'take_profit',
        'signal_type' => 'bollinger_overheat',
        'observed_week' => '2026-09-14',
        'snapshot_id' => $snapshot->id,
        'metrics' => ['close' => 130.0, 'rsi' => 100.0],
    ]);

    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    $occurrence = SignalOccurrence::where('holding_id', $holding->id)->sole();
    expect($occurrence->metrics)->toEqualCanonicalizing(['close' => 130.0, 'rsi' => 100.0]);
});

test('移送対象がなくても正常終了する', function () {
    // Act
    $this->artisan('signal-outcomes:backfill')->assertSuccessful();

    // Assert
    expect(SignalOccurrence::count())->toBe(0);
});
