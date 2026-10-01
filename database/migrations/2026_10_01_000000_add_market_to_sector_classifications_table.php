<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CHG-0029 / ADR-0020: separate JP / US / mutual-fund sector rows.
     * Existing rows are all J-Quants sectors, hence the 'jp' default. The
     * name-only unique index becomes (market, name) so the same label can
     * exist in different markets (sector_classifications is a few dozen
     * rows, so the index swap is cheap).
     */
    public function up(): void
    {
        Schema::table('sector_classifications', function (Blueprint $table) {
            $table->string('market', 12)->default('jp')->after('id');
        });

        Schema::table('sector_classifications', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['market', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('sector_classifications', function (Blueprint $table) {
            $table->dropUnique(['market', 'name']);
            $table->unique(['name']);
        });

        Schema::table('sector_classifications', function (Blueprint $table) {
            $table->dropColumn('market');
        });
    }
};
