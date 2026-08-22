<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#fundamental_indicators
     * (ADR-0004, Gate 4承認済み: FetchExternalMarketDataAction). Adds
     * `eps_growth`/`peg_ratio` without touching the already-applied
     * `2026_08_16_030020_create_fundamental_indicators_table` migration
     * (`.claude/rules/20-mysql.md`).
     */
    public function up(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->decimal('eps_growth', 7, 4)->nullable()->after('dividend_payout_ratio');
            $table->decimal('peg_ratio', 10, 4)->nullable()->after('eps_growth');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->dropColumn(['eps_growth', 'peg_ratio']);
        });
    }
};
