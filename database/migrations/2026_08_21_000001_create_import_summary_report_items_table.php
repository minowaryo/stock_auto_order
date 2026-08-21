<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#import_summary_report_items
     * (UC-009, Gate 3承認済み). `composite_score`'s calculation weights are
     * intentionally undisclosed (ADR-0003) but the score itself is persisted
     * for rank reproducibility.
     */
    public function up(): void
    {
        Schema::create('import_summary_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_summary_report_id')->constrained('import_summary_reports');
            $table->unsignedTinyInteger('rank');
            $table->boolean('is_supplementary')->default(false);
            $table->enum('recommendation_type', ['利確検討', 'リバランス', '新規投資候補']);
            $table->string('target_label', 255);
            $table->string('action_suggestion', 255);
            $table->string('reason_summary', 255);
            $table->string('link_to', 50);
            $table->decimal('composite_score', 10, 4);

            $table->unique(['import_summary_report_id', 'rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_summary_report_items');
    }
};
