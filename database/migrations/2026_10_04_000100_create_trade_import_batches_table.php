<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#trade_import_batches (UC-017 /
     * F-017 / ADR-0027, CHG-0033, Gate 3承認済み 2026-10-04).
     *
     * One row per confirmed trade history import (both markets together).
     * Previews are not stored.
     */
    public function up(): void
    {
        Schema::create('trade_import_batches', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('jp_filename');
            $table->string('us_filename');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('new_rows')->default(0);
            $table->unsignedInteger('existing_rows')->default(0);
            $table->unsignedInteger('missing_rows')->default(0);
            $table->string('failure_reason')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('imported_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_import_batches');
    }
};
