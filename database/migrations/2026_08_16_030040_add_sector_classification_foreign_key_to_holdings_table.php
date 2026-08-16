<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `2026_08_16_024151_create_holdings_table` deliberately left
     * `sector_classification_id` without a foreign key constraint because
     * `sector_classifications` did not exist yet at that time. Now that this
     * TDD cycle (UC-002) creates that table, add the deferred FK here rather
     * than editing the already-applied holdings migration
     * (`.claude/rules/20-mysql.md`: already-run migration files are not
     * edited; back-fill via a follow-up migration instead).
     *
     * See docs/architecture/data-model.md#holdings (UC-002, Gate 3承認済み).
     */
    public function up(): void
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->foreign('sector_classification_id')
                ->references('id')->on('sector_classifications');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->dropForeign(['sector_classification_id']);
        });
    }
};
