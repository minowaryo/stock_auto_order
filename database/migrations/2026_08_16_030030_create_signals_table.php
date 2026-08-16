<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#signals (UC-004, Gate 3承認済み).
     * Append-only history rows (like holding_snapshots), so only created_at
     * is kept — no updated_at.
     */
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_snapshot_id')->constrained('holding_snapshots');
            $table->enum('signal_type', ['rsi_reversal', 'macd_dead_cross', 'bollinger_overheat']);
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
        Schema::dropIfExists('signals');
    }
};
