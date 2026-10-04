<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#indicator_observations (UC-018 /
     * F-017 / ADR-0027 D4, CHG-0033, Gate 3承認済み 2026-10-04).
     *
     * Append-only log of the indicators the weekly analysis obtained, so the
     * state at trade time can be linked later. technical_indicators /
     * fundamental_indicators are overwritten caches and cannot be rebuilt
     * afterwards, which is why recording starts before the rest of UC-018.
     */
    public function up(): void
    {
        Schema::create('indicator_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->enum('source', ['holding_import', 'watchlist_refresh']);
            $table->timestamp('observed_at');
            $table->json('metrics');
            $table->timestamp('created_at')->useCurrent();

            // Leading holding_id column doubles as the FK index.
            $table->index(['holding_id', 'observed_at']);
            $table->index('observed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicator_observations');
    }
};
