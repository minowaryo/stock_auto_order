<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#holding_snapshot_accounts (UC-001/004/005/008, ADR-0002).
     */
    public function up(): void
    {
        Schema::create('holding_snapshot_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_snapshot_id')->constrained('holding_snapshots');
            $table->enum('account_type', ['specific', 'general', 'nisa_growth', 'nisa_tsumitate']);
            $table->decimal('quantity', 15, 2);
            $table->decimal('average_cost', 15, 2);
            $table->timestamp('created_at')->nullable();

            $table->unique(['holding_snapshot_id', 'account_type'], 'holding_snapshot_accounts_snapshot_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holding_snapshot_accounts');
    }
};
