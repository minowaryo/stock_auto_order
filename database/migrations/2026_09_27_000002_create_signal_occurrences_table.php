<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#signal_occurrences (UC-014 /
     * F-014 / ADR-0017 D3, CHG-0020, Gate 3承認済み 2026-09-27).
     *
     * Append-only log of signals that actually fired, with the metrics used
     * at determination time. Does not touch signals / buy_signals /
     * watchlist_buy_signals. Excess returns are not stored (computed on read
     * from weekly_prices / index_weekly_prices, D4).
     */
    public function up(): void
    {
        Schema::create('signal_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->enum('source', ['take_profit', 'buy', 'watchlist_buy']);
            $table->string('signal_type', 50);
            $table->date('observed_week');
            $table->foreignId('snapshot_id')->nullable()->constrained('snapshots');
            $table->json('metrics')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Leading holding_id column doubles as the FK index.
            $table->unique(['holding_id', 'source', 'signal_type', 'observed_week'], 'signal_occurrences_holding_source_type_week_unique');
            $table->index(['source', 'signal_type', 'observed_week'], 'signal_occurrences_source_type_week_index');
            $table->index('snapshot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signal_occurrences');
    }
};
