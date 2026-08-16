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
     * (UC-002/003/004/006/008/009, Gate 3承認済み). This is a current-value
     * UPSERT cache keyed 1:1 by holding_id (not a weekly history table), so
     * it has no created_at/updated_at columns — only computed_at.
     */
    public function up(): void
    {
        Schema::create('technical_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->unique()->constrained('holdings');
            $table->decimal('rsi', 5, 2)->nullable();
            $table->decimal('macd', 10, 4)->nullable();
            $table->decimal('macd_signal', 10, 4)->nullable();
            $table->decimal('ma20', 15, 2)->nullable();
            $table->decimal('ma75', 15, 2)->nullable();
            $table->decimal('bb_upper', 15, 2)->nullable();
            $table->decimal('bb_lower', 15, 2)->nullable();
            $table->timestamp('computed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technical_indicators');
    }
};
