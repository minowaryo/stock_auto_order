<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#technical_indicators
     * (ADR-0004, Gate 4承認済み: FetchExternalMarketDataAction). Adds the
     * indicator-expansion columns without touching the already-applied
     * `2026_08_16_030010_create_technical_indicators_table` migration
     * (`.claude/rules/20-mysql.md`: already-run migration files are not
     * edited).
     */
    public function up(): void
    {
        Schema::table('technical_indicators', function (Blueprint $table) {
            $table->unsignedBigInteger('volume')->nullable()->after('bb_lower');
            $table->unsignedBigInteger('volume_ma20')->nullable()->after('volume');
            $table->decimal('week52_high', 15, 2)->nullable()->after('volume_ma20');
            $table->decimal('week52_low', 15, 2)->nullable()->after('week52_high');
            $table->decimal('relative_strength_vs_market', 7, 4)->nullable()->after('week52_low');
            $table->decimal('relative_strength_vs_sector', 7, 4)->nullable()->after('relative_strength_vs_market');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('technical_indicators', function (Blueprint $table) {
            $table->dropColumn([
                'volume',
                'volume_ma20',
                'week52_high',
                'week52_low',
                'relative_strength_vs_market',
                'relative_strength_vs_sector',
            ]);
        });
    }
};
