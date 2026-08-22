<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#market_indicator_snapshots
     * (UC-007, ADR-0004, Gate 4承認済み: FetchExternalMarketDataAction).
     * Append-only history rows (time-series data, one row per snapshot per
     * index_name) — only created_at is kept, no updated_at.
     */
    public function up(): void
    {
        Schema::create('market_indicator_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('snapshots');
            $table->enum('index_name', ['nikkei225', 'sp500', 'us10y', 'vix', 'usdjpy']);
            $table->decimal('value', 15, 4);
            $table->decimal('change_rate', 7, 4)->nullable();
            $table->decimal('ma_deviation', 7, 4)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['snapshot_id', 'index_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_indicator_snapshots');
    }
};
