<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#index_weekly_prices (UC-015 /
     * ADR-0019 D2, CHG-0026): allow the SOX index weekly bars to be recorded
     * alongside nikkei225/sp500.
     */
    public function up(): void
    {
        Schema::table('index_weekly_prices', function (Blueprint $table) {
            $table->enum('index_name', ['nikkei225', 'sp500', 'sox'])->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * 'sox' rows cannot exist under the previous enum, so they are removed first.
     */
    public function down(): void
    {
        DB::table('index_weekly_prices')->where('index_name', 'sox')->delete();

        Schema::table('index_weekly_prices', function (Blueprint $table) {
            $table->enum('index_name', ['nikkei225', 'sp500'])->change();
        });
    }
};
