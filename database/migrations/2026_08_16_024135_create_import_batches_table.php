<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#import_batches (UC-001, Gate 3承認済み).
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('jp_stock_filename');
            $table->string('us_stock_filename');
            $table->string('mutual_fund_filename')->nullable();
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
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
        Schema::dropIfExists('import_batches');
    }
};
