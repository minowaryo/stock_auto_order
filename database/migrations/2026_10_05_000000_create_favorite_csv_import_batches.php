<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_csv_import_batches', function (Blueprint $table) {
            $table->id();
            $table->timestamp('imported_at');
            $table->unsignedInteger('registered_count');
            $table->timestamps();
        });

        Schema::create('favorite_csv_import_states', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('latest_batch_id')->nullable()->constrained('favorite_csv_import_batches');
            $table->timestamps();
        });

        DB::table('favorite_csv_import_states')->insert([
            'id' => 1,
            'latest_batch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->foreignId('last_seen_favorite_import_id')
                ->nullable()
                ->constrained('favorite_csv_import_batches');
            $table->index('last_seen_favorite_import_id');
        });
    }

    public function down(): void
    {
        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_seen_favorite_import_id');
        });

        Schema::dropIfExists('favorite_csv_import_states');
        Schema::dropIfExists('favorite_csv_import_batches');
    }
};
