<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#trade_reconciliation_items (UC-017 /
     * F-017 / ADR-0027, CHG-0033, Gate 3承認済み 2026-10-04).
     *
     * Append-only: each trade history import records how the reconstructed
     * share counts compared with the latest holdings snapshot.
     */
    public function up(): void
    {
        Schema::create('trade_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_import_batch_id')->constrained('trade_import_batches');
            $table->foreignId('snapshot_id')->constrained('snapshots');
            $table->foreignId('holding_id')->constrained('holdings');
            $table->enum('account_type', ['specific', 'general', 'nisa_growth', 'nisa_tsumitate'])->nullable();
            $table->decimal('history_quantity', 15, 4)->nullable();
            $table->decimal('snapshot_quantity', 15, 4)->nullable();
            $table->enum('status', ['matched', 'snapshot_only', 'history_only', 'needs_review']);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Leading trade_import_batch_id column doubles as the FK index.
            $table->index(['trade_import_batch_id', 'status']);
            $table->index('snapshot_id');
            $table->index('holding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_reconciliation_items');
    }
};
