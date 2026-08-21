<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#watched_themes (UC-008/UC-009, Gate
     * 3承認済み). No `deleted_at`: UC-008 has no registration-cancel flow
     * defined yet (.claude/rules/30-testing.md CRUD網羅ルール — do not
     * pre-build unspecified delete functionality).
     */
    public function up(): void
    {
        Schema::create('watched_themes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watched_themes');
    }
};
