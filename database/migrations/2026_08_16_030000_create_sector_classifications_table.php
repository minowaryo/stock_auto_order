<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#sector_classifications (UC-002/005, Gate 3承認済み).
     * "未分類" is represented by holdings.sector_classification_id = null, so
     * no "未分類" row is created here.
     */
    public function up(): void
    {
        Schema::create('sector_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
            $table->string('name', 100)->unique();
            $table->timestamps();

            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sector_classifications');
    }
};
