<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#watchlist_buy_signals (UC-012,
     * ADR-0015, CHG-0018). Widens `signal_type` from 7 to 8 enum values
     * (adds per_undervalued, ADR-0015 D2), mirroring the same change on
     * `buy_signals` (2026_09_19_000000_add_per_undervalued_to_buy_signals_
     * table.php) since both tables share the same signal_type value domain.
     *
     * Uses raw SQL (`DB::statement`) rather than Schema::table()->enum()
     * ->change() for the same doctrine/dbal-absence reason documented in
     * 2026_08_22_000002_add_adr0004_signal_types_to_signals_table.php.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE watchlist_buy_signals MODIFY signal_type ENUM('
            ."'rsi_oversold_rebound',"
            ."'macd_golden_cross',"
            ."'bollinger_oversold',"
            ."'week52_low_proximity',"
            ."'ma_deviation_oversold',"
            ."'volume_spike_rebound',"
            ."'peg_undervalued',"
            ."'per_undervalued'"
            .') NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE watchlist_buy_signals MODIFY signal_type ENUM('
            ."'rsi_oversold_rebound',"
            ."'macd_golden_cross',"
            ."'bollinger_oversold',"
            ."'week52_low_proximity',"
            ."'ma_deviation_oversold',"
            ."'volume_spike_rebound',"
            ."'peg_undervalued'"
            .') NOT NULL'
        );
    }
};
