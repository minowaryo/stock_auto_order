<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#weekly_prices (UC-014 / F-014 /
     * ADR-0017 D2, D5, CHG-0020, Gate 3承認済み 2026-09-27).
     *
     * Stores the weekly bars already fetched for indicator calculation
     * (no new external API call). Overwrite-type time series: rows are
     * UPSERTed by (holding_id, week_date) on every fetch so Yahoo's
     * retroactive split adjustments are followed. `close` is split-adjusted
     * but NOT dividend-adjusted (quote.close, ADR-0017 D5).
     */
    public function up(): void
    {
        Schema::create('weekly_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->date('week_date');
            $table->decimal('close', 15, 4);
            $table->unsignedBigInteger('volume')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // Leading holding_id column doubles as the FK index.
            $table->unique(['holding_id', 'week_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_prices');
    }
};
