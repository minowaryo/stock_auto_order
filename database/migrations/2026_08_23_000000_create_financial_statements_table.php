<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#financial_statements
     * (UC-006, Gate 3承認済み). Unlike technical_indicators/
     * fundamental_indicators, this is a true historical table keyed by
     * fiscal_period (UPSERT by (holding_id, fiscal_period), not a single
     * current-value row per holding) — so it has created_at but no
     * updated_at.
     */
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->string('fiscal_period', 20);
            $table->decimal('revenue', 18, 2);
            $table->decimal('operating_income', 18, 2);
            $table->decimal('eps', 10, 2)->nullable();
            $table->decimal('revenue_yoy_change', 7, 4)->nullable();
            $table->decimal('operating_income_yoy_change', 7, 4)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['holding_id', 'fiscal_period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
