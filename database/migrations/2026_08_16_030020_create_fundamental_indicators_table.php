<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#fundamental_indicators
     * (UC-002/003/006/008/009, Gate 3承認済み). Same UPSERT-cache shape as
     * technical_indicators: 1:1 by holding_id, no created_at/updated_at,
     * only fetched_at.
     */
    public function up(): void
    {
        Schema::create('fundamental_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->unique()->constrained('holdings');
            $table->decimal('per', 10, 2)->nullable();
            $table->decimal('pbr', 10, 2)->nullable();
            $table->decimal('roe', 7, 4)->nullable();
            $table->decimal('revenue_growth', 7, 4)->nullable();
            $table->decimal('operating_income_growth', 7, 4)->nullable();
            $table->decimal('equity_ratio', 7, 4)->nullable();
            $table->decimal('dividend_yield', 7, 4)->nullable();
            $table->decimal('dividend_payout_ratio', 7, 4)->nullable();
            $table->timestamp('fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fundamental_indicators');
    }
};
