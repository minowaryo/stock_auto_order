<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#buy_signals (UC-010, ADR-0015,
     * CHG-0018). Widens `signal_type` from 7 to 8 enum values (adds
     * per_undervalued, ADR-0015 D2).
     *
     * Uses raw SQL (`DB::statement`) rather than Schema::table()->enum()
     * ->change() because this project does not install doctrine/dbal and
     * Laravel's native column-modification support does not cover MySQL
     * ENUM value lists. `.claude/rules/10-laravel.md`/`.claude/rules/
     * 20-mysql.md` require raw SQL and column-type changes to be backed by
     * an ADR — this is explicitly covered by
     * docs/adr/ADR-0015-undervalued-quality-buy-signal.md, which documents
     * this exact ENUM widening as an accepted "危険な操作" given the
     * personal-use scale of this application (same precedent as
     * 2026_08_22_000002_add_adr0004_signal_types_to_signals_table.php).
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE buy_signals MODIFY signal_type ENUM('
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
            'ALTER TABLE buy_signals MODIFY signal_type ENUM('
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
