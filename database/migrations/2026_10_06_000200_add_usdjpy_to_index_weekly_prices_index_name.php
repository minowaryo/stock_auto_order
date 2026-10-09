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
     * See docs/architecture/data-model.md#index_weekly_prices (UC-018 /
     * ADR-0027 D5, CHG-0033): record the USD/JPY weekly series alongside the
     * indices (it is not an index, but WeeklyPriceRecorder::recordIndex()
     * and the week normalization are reused as is). Appending an enum value
     * is backward compatible; existing rows are untouched.
     */
    public function up(): void
    {
        Schema::table('index_weekly_prices', function (Blueprint $table) {
            $table->enum('index_name', ['nikkei225', 'sp500', 'sox', 'usdjpy'])->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * 'usdjpy' rows cannot exist under the previous enum, so they are removed first.
     */
    public function down(): void
    {
        DB::table('index_weekly_prices')->where('index_name', 'usdjpy')->delete();

        Schema::table('index_weekly_prices', function (Blueprint $table) {
            $table->enum('index_name', ['nikkei225', 'sp500', 'sox'])->change();
        });
    }
};
