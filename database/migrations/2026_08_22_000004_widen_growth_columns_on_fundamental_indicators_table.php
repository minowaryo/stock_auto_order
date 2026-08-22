<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/adr/ADR-0006-widen-fundamental-growth-columns.md
     * (Gate 4承認済み: FetchExternalMarketDataAction ADR-0004回帰テスト).
     * Widens `eps_growth`/`revenue_growth`/`operating_income_growth` from
     * decimal(7,4) (max ±999.9999%) to decimal(10,4) (max ±999999.9999%)
     * because a real near-zero-EPS recovery holding produced ~1136% growth,
     * causing MySQL strict mode to raise "Out of range value" on INSERT.
     * Does not touch the already-applied
     * `2026_08_16_030020_create_fundamental_indicators_table` /
     * `2026_08_22_000001_add_adr0004_columns_to_fundamental_indicators_table`
     * migrations (`.claude/rules/20-mysql.md`).
     */
    public function up(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->decimal('eps_growth', 10, 4)->nullable()->change();
            $table->decimal('revenue_growth', 10, 4)->nullable()->change();
            $table->decimal('operating_income_growth', 10, 4)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->decimal('eps_growth', 7, 4)->nullable()->change();
            $table->decimal('revenue_growth', 7, 4)->nullable()->change();
            $table->decimal('operating_income_growth', 7, 4)->nullable()->change();
        });
    }
};
