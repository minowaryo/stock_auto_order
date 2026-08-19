<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#holding_memos (UC-003, Gate 3承認済み).
     * Append-only (追記のみ・編集不可、UC-003業務ルール) — only recorded_at is
     * kept, no updated_at.
     */
    public function up(): void
    {
        Schema::create('holding_memos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->text('body');
            $table->timestamp('recorded_at')->useCurrent();

            $table->index('holding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holding_memos');
    }
};
