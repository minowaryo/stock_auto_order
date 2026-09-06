<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/adr/ADR-0011-operating-margin-health-criterion.md (D3) and
     * docs/architecture/data-model.md#fundamental_indicators (CHG-0012).
     * Adds `operating_margin` as the 4th financial-health-filter criterion
     * without touching the already-applied
     * `2026_08_16_030020_create_fundamental_indicators_table` /
     * `2026_08_22_000001_add_adr0004_columns_to_fundamental_indicators_table`
     * migrations (`.claude/rules/20-mysql.md`).
     *
     * `decimal(10,4)` matches the growth columns widened by ADR-0006 — the JP
     * `operating_profit / net_sales` ratio for a near-zero-revenue holding can
     * blow past `decimal(7,4)`'s ±999.9999% the same way `eps_growth` did.
     * (The mappers additionally null-out |value| > 999%, ADR-0011 D5.)
     */
    public function up(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->decimal('operating_margin', 10, 4)->nullable()->after('equity_ratio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->dropColumn('operating_margin');
        });
    }
};
