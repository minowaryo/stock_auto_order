<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/adr/ADR-0008-nullable-financial-statement-columns.md
     * (/review拡張レベル指摘、MEDIUM: UC-006 Cycle A). JQuantsClient::
     * fetchStatements()のnet_sales/operating_profitはfloat|nullとして
     * 返り得るが、`financial_statements.revenue`/`operating_income`は
     * NOT NULLで定義されていたため、J-Quantsが該当期のSales/OPを欠損で
     * 返す銘柄でINSERTがQueryExceptionとなり、DB::transaction()配下の
     * 同一銘柄のtechnical_indicators/fundamental_indicators/signals更新
     * まで巻き添えでロールバックしていた。
     * Does not touch the already-applied
     * `2026_08_23_000000_create_financial_statements_table` migration
     * (`.claude/rules/20-mysql.md`).
     */
    public function up(): void
    {
        Schema::table('financial_statements', function (Blueprint $table) {
            $table->decimal('revenue', 18, 2)->nullable()->change();
            $table->decimal('operating_income', 18, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_statements', function (Blueprint $table) {
            $table->decimal('revenue', 18, 2)->nullable(false)->change();
            $table->decimal('operating_income', 18, 2)->nullable(false)->change();
        });
    }
};
