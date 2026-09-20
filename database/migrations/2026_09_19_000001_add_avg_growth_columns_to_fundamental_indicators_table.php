<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md (D2)
     * and docs/architecture/data-model.md#fundamental_indicators (CHG-0017,
     * Gate4 Cycle4a). Adds storage for
     * FundamentalIndicatorMapper::averageAnnualGrowth()'s direct 3-period
     * (最大5期のうち直近3期) average YoY growth rate, so
     * FetchExternalMarketDataAction can persist it separately from the
     * existing single-year revenue_growth/operating_income_growth columns.
     * JP株限定（financial_statements自体がJP株限定のため、US株は常にnull、
     * ADR-0009と同じ理由）。
     *
     * Does not touch already-applied migrations
     * (`2026_08_16_030020_create_fundamental_indicators_table`,
     * `2026_08_22_000001_add_adr0004_columns_to_fundamental_indicators_table`,
     * `2026_08_22_000004_widen_growth_columns_on_fundamental_indicators_table`,
     * `2026_09_06_000001_add_operating_margin_to_fundamental_indicators_table`)
     * (`.claude/rules/20-mysql.md`).
     *
     * `decimal(10,4)` matches the existing revenue_growth/
     * operating_income_growth/operating_margin columns (ADR-0006/ADR-0011)
     * for the same reason (an average of near-zero-base YoY swings can
     * exceed decimal(7,4)'s ±999.9999%).
     */
    public function up(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->decimal('avg_revenue_growth', 10, 4)->nullable()->after('operating_income_growth');
            $table->decimal('avg_operating_income_growth', 10, 4)->nullable()->after('avg_revenue_growth');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fundamental_indicators', function (Blueprint $table) {
            $table->dropColumn(['avg_revenue_growth', 'avg_operating_income_growth']);
        });
    }
};
