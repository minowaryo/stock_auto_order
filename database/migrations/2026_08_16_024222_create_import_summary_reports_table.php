<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#import_summary_reports (UC-009, Gate 3承認済み).
     *
     * Only the columns needed for UC-001's auto-generation trigger are
     * created here. `import_summary_report_items` (UC-009 detail rows) is
     * out of scope for this TDD cycle (see UC-009's own /tdd cycle).
     */
    public function up(): void
    {
        Schema::create('import_summary_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->unique()->constrained('import_batches');
            $table->string('portfolio_headline', 500);
            $table->timestamp('generated_at');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_summary_reports');
    }
};
