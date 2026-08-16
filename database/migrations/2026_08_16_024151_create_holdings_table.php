<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#holdings (UC-001/002/003, Gate 3承認済み).
     *
     * `sector_classification_id` is kept as a plain nullable column without a
     * foreign key constraint for now, because `sector_classifications` is out
     * of scope for this migration (UC-002/005 territory). A future migration
     * should add the FK once that table exists (`.claude/rules/20-mysql.md`
     * safe/staged migration policy).
     *
     * DEVIATION FROM data-model.md: `symbol_code` is declared there as
     * varchar(20), but UC-001業務ルール defines the mutual fund symbol_code as
     * the fund's full name (e.g. "楽天・全米株式インデックス・ファンド(楽天・VTI)"),
     * which regularly exceeds 20 characters. Widened to varchar(255) so the
     * Gate 4-approved Feature Test (`tests/Feature/UC001CsvImportTest.php`,
     * mutual fund import case) can pass. Flagged for data-model.md follow-up.
     */
    public function up(): void
    {
        Schema::create('holdings', function (Blueprint $table) {
            $table->id();
            $table->string('symbol_code');
            $table->enum('market', ['jp', 'us', 'mutual_fund']);
            $table->enum('instrument_type', ['stock', 'etf', 'mutual_fund']);
            $table->string('symbol_name');
            $table->unsignedBigInteger('sector_classification_id')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamps();

            $table->unique(['symbol_code', 'market']);
            $table->index('sector_classification_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holdings');
    }
};
