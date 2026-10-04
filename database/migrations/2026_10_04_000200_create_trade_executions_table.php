<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#trade_executions (UC-017 / UC-018 /
     * F-017 / ADR-0027 D1, CHG-0033, Gate 3承認済み 2026-10-04).
     *
     * Trades / transfers from the full-history CSVs. (market, content_hash,
     * occurrence_index) makes full-history re-imports idempotent while
     * keeping identical trades as separate rows. Rows are never deleted;
     * a row missing from the latest history is flagged instead.
     */
    public function up(): void
    {
        Schema::create('trade_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->enum('market', ['jp', 'us']);
            $table->date('trade_date');
            $table->date('settlement_date')->nullable();
            $table->enum('account_type', ['specific', 'general', 'nisa_growth', 'nisa_tsumitate']);
            $table->enum('kind', ['buy', 'sell', 'transfer_in', 'transfer_out', 'tsumitate', 'split_in']);
            $table->boolean('is_routine')->default(false);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->enum('price_currency', ['jpy', 'usd']);
            $table->enum('settlement_currency', ['jpy', 'usd'])->nullable();
            $table->decimal('settlement_amount_jpy', 15, 2)->nullable();
            $table->decimal('settlement_amount_usd', 15, 2)->nullable();
            $table->decimal('fx_rate', 10, 4)->nullable();
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->char('content_hash', 64);
            $table->unsignedSmallInteger('occurrence_index');
            $table->json('source_row');
            $table->foreignId('first_import_batch_id')->constrained('trade_import_batches');
            $table->foreignId('last_seen_import_batch_id')->constrained('trade_import_batches');
            $table->enum('review_status', ['ok', 'missing_in_latest'])->default('ok');
            $table->timestamps();

            $table->unique(['market', 'content_hash', 'occurrence_index'], 'trade_executions_market_hash_occurrence_unique');
            $table->index(['holding_id', 'trade_date']);
            $table->index('trade_date');
            $table->index('first_import_batch_id');
            $table->index('last_seen_import_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_executions');
    }
};
