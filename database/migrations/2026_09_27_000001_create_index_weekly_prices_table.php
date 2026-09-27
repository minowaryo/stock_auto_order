<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#index_weekly_prices (UC-014 /
     * F-014 / ADR-0017 D2, CHG-0020, Gate 3承認済み 2026-09-27).
     *
     * Weekly bars of the market indices already fetched by MarketIndexClient,
     * kept as a series for excess-return calculation (price indices without
     * dividends). Independent from market_indicator_snapshots (latest point
     * per snapshot).
     */
    public function up(): void
    {
        Schema::create('index_weekly_prices', function (Blueprint $table) {
            $table->id();
            $table->enum('index_name', ['nikkei225', 'sp500']);
            $table->date('week_date');
            $table->decimal('close', 15, 4);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['index_name', 'week_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_weekly_prices');
    }
};
