<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#price_tracking_targets (UC-018 /
     * ADR-0024 D6, CHG-0033 Cycle 6e, Gate 3承認済み 2026-10-10).
     *
     * When the last 10-year fetch succeeded. A stock split recorded after it
     * (by the 104-week holdings / watchlist refresh) makes price:track refetch
     * 10 years, so the older weeks get the split-adjusted closes too.
     * Nullable: existing rows stay as they are (null falls back to created_at).
     */
    public function up(): void
    {
        Schema::table('price_tracking_targets', function (Blueprint $table) {
            $table->timestamp('full_history_fetched_at')->nullable()->after('backfilled_from_week');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_tracking_targets', function (Blueprint $table) {
            $table->dropColumn('full_history_fetched_at');
        });
    }
};
