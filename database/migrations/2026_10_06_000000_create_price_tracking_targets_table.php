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
     * F-017 / ADR-0024 D1・D2, ADR-0027 D7, CHG-0033, Gate 3承認済み
     * 2026-10-04; track_until_week nullable and splits_incomplete added at
     * Gate 4 Cycle 6b, 2026-10-06).
     *
     * One state row per holding: until when its weekly prices must be
     * fetched, what was verified as saved, and how often the fetch failed.
     * track_until_week is null for a holding that is only backfilled and
     * covered by the holdings / watchlist refresh.
     */
    public function up(): void
    {
        Schema::create('price_tracking_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->date('last_sell_week')->nullable();
            $table->date('last_signal_week')->nullable();
            $table->date('track_until_week')->nullable();
            $table->enum('status', ['active', 'completed', 'unavailable'])->default('active');
            $table->date('latest_saved_week')->nullable();
            $table->date('backfilled_from_week')->nullable();
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->boolean('splits_incomplete')->default(false);
            $table->string('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();

            $table->unique('holding_id');
            $table->index(['status', 'track_until_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_tracking_targets');
    }
};
