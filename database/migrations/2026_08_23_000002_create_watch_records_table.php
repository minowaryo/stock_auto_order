<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#watch_records (UC-006, Gate 3承認済み).
     * Append-only (追記のみ・編集不可) — only recorded_at is kept, no updated_at
     * (same pattern as holding_memos).
     */
    public function up(): void
    {
        Schema::create('watch_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->enum('watch_status', ['様子見', '買い時', '次回購入候補', 'リバランス対象'])->nullable();
            $table->text('memo')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->index(['holding_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watch_records');
    }
};
