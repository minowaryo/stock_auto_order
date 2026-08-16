<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#holding_snapshots (UC-001/002/003/004, Gate 3承認済み).
     */
    public function up(): void
    {
        Schema::create('holding_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots');
            $table->foreignId('holding_id')->constrained('holdings');
            $table->decimal('quantity', 15, 2);
            $table->decimal('average_cost', 15, 2);
            $table->decimal('current_price', 15, 2);
            $table->decimal('fx_rate_used', 10, 4)->nullable();
            $table->decimal('unrealized_gain_amount', 15, 2);
            $table->decimal('unrealized_gain_rate', 7, 4);
            $table->decimal('ma20', 15, 2)->nullable();
            $table->decimal('ma75', 15, 2)->nullable();
            $table->boolean('is_newly_detected')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->unique(['snapshot_id', 'holding_id']);
            $table->index('holding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holding_snapshots');
    }
};
