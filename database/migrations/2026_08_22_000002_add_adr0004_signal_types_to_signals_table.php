<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#signals (ADR-0004, Gate 4承認済み:
     * FetchExternalMarketDataAction). Widens `signal_type` from 3 to 7 enum
     * values (adds week52_high_pullback/peg_overvalued/
     * relative_strength_weakening/volume_spike_decline).
     *
     * Uses raw SQL (`DB::statement`) rather than Schema::table()->enum()
     * ->change() because this project does not install doctrine/dbal and
     * Laravel's native column-modification support does not cover MySQL
     * ENUM value lists. `.claude/rules/10-laravel.md`/`.claude/rules/
     * 20-mysql.md` require raw SQL and column-type changes to be backed by
     * an ADR — this is explicitly covered by
     * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md, which
     * already documents this exact ENUM widening as an accepted
     * "危険な操作" given the personal-use scale of this application.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE signals MODIFY signal_type ENUM("
            ."'rsi_reversal',"
            ."'macd_dead_cross',"
            ."'bollinger_overheat',"
            ."'week52_high_pullback',"
            ."'peg_overvalued',"
            ."'relative_strength_weakening',"
            ."'volume_spike_decline'"
            .") NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE signals MODIFY signal_type ENUM("
            ."'rsi_reversal',"
            ."'macd_dead_cross',"
            ."'bollinger_overheat'"
            .") NOT NULL"
        );
    }
};
