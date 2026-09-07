<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#watchlist_items (UC-012 / F-012 /
     * ADR-0013, Gate 3承認済み 2026-09-08).
     *
     * お気に入り銘柄CSV由来・または画面上で★手動登録した銘柄のうち未保有分の
     * ウォッチリスト。指標は既存の technical_indicators / fundamental_indicators
     * / financial_statements を holding_id 経由で共有する（新規の指標テーブルは
     * 作らない）。
     */
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->string('folder_name')->nullable();
            $table->string('exchange_label', 20)->nullable();
            $table->enum('source', ['rakuten_favorites_csv', 'manual'])->default('rakuten_favorites_csv');
            $table->boolean('is_starred')->default(false);
            $table->timestamp('last_seen_in_csv_at')->nullable();
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamps();

            $table->unique('holding_id');
            $table->index('is_starred');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
    }
};
