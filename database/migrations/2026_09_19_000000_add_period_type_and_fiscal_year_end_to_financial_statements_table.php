<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md (D2,
     * "2026-09-19追記") and docs/architecture/data-model.md#financial_statements
     * (CHG-0017). `JQuantsClient::fetchStatements()`'s response already
     * includes `period_type` (J-Quants `CurPerType`) and `fiscal_year_end`
     * (J-Quants `CurFYEn`) — added to the client response shape by ADR-0012
     * for `FundamentalIndicatorMapper::annualGrowth()`'s FY-vs-FY comparison
     * — but `FetchExternalMarketDataAction` has never persisted these 2
     * fields onto `financial_statements` rows. Without them, DB rows alone
     * cannot distinguish which disclosure is the full-year (FY) filing
     * (confirmed with real data for 8001, where cumulative quarterly
     * disclosures within the same fiscal year make this ambiguous). This is
     * an additive, nullable, low-risk schema change per
     * `.claude/rules/20-mysql.md` — no existing `2026_08_23_000000_create_
     * financial_statements_table` / `2026_08_23_000001_nullable_revenue_
     * operating_income_on_financial_statements_table` migration is edited.
     *
     * Column placement: after `eps`, before `revenue_yoy_change` (natural
     * "raw disclosure fields" → "derived YoY fields" ordering, matching the
     * table definition order in docs/architecture/data-model.md).
     */
    public function up(): void
    {
        Schema::table('financial_statements', function (Blueprint $table) {
            $table->string('period_type', 10)->nullable()->after('eps');
            $table->string('fiscal_year_end', 20)->nullable()->after('period_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_statements', function (Blueprint $table) {
            $table->dropColumn(['period_type', 'fiscal_year_end']);
        });
    }
};
