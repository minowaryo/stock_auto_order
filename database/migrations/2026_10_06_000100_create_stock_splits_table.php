<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#stock_splits (UC-018 / ADR-0027
     * D6, CHG-0033, Gate 3承認済み 2026-10-04).
     *
     * Yahoo closes are split-adjusted, so a trade's share count is adjusted
     * with this table before it is valued. Upserted by (holding_id,
     * effective_date); never deleted.
     */
    public function up(): void
    {
        Schema::create('stock_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->date('effective_date');
            $table->unsignedInteger('ratio_numerator');
            $table->unsignedInteger('ratio_denominator');
            $table->enum('source', ['yahoo', 'trade_history']);
            $table->timestamp('created_at')->useCurrent();

            // Leading holding_id column doubles as the FK index.
            $table->unique(['holding_id', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_splits');
    }
};
