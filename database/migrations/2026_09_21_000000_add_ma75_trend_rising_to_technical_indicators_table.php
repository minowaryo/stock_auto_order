<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md (D5)
     * and docs/architecture/data-model.md#technical_indicators (CHG-0017,
     * Gate4 Cycle5). 押し目買いの事前条件に週足MA75の中期トレンド確認
     * （直近値が13週前の値より上向き）を追加するための保存先。
     * TechnicalIndicatorCalculator が既に保持する週足終値シリーズから
     * 追加の外部データ取得なしに算出できるため、`FetchExternalMarketDataAction`
     * 等の呼び出し元に新規のAPI呼び出しは発生しない（CHG-0012の
     * `operating_margin`追加と同型の軽量ADD COLUMN）。
     *
     * Does not touch already-applied migrations
     * (`2026_08_16_030010_create_technical_indicators_table`,
     * `2026_08_22_000000_add_adr0004_columns_to_technical_indicators_table`)
     * (`.claude/rules/20-mysql.md`).
     */
    public function up(): void
    {
        Schema::table('technical_indicators', function (Blueprint $table) {
            $table->boolean('ma75_trend_rising')->nullable()->after('relative_strength_vs_sector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('technical_indicators', function (Blueprint $table) {
            $table->dropColumn('ma75_trend_rising');
        });
    }
};
