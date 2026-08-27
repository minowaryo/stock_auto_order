<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#buy_signals (UC-010, ADR-0007,
     * Gate 3承認済み). Append-only history rows (like `signals`), so only
     * created_at is kept — no updated_at. Intentionally a separate table
     * from `signals` (ADR-0007 D2) so the existing take-profit persistence/
     * list/scoring logic never needs to be touched.
     */
    public function up(): void
    {
        Schema::create('buy_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_snapshot_id')->constrained('holding_snapshots');
            $table->enum('signal_type', [
                'rsi_oversold_rebound',
                'macd_golden_cross',
                'bollinger_oversold',
                'week52_low_proximity',
                'ma_deviation_oversold',
                'volume_spike_rebound',
                'peg_undervalued',
            ]);
            $table->string('reason_summary');
            $table->timestamp('created_at')->nullable();

            $table->unique(['holding_snapshot_id', 'signal_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buy_signals');
    }
};
