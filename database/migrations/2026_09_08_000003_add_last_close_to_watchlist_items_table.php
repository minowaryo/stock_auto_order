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
     * ADR-0013, Gate 4 refinement 2026-09-08).
     *
     * 未保有銘柄には holding_snapshots.current_price が無いため、「現在値」の
     * 表示・52週レンジ内位置・判定チェックリストの価格乖離チップに使う現在値を
     * RefreshWatchlistMarketDataAction が週足の直近終値として保存する（既に
     * 取得済みのデータで、追加の外部API呼び出しはない）。
     */
    public function up(): void
    {
        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->decimal('last_close', 15, 2)->nullable()->after('exchange_label');
            $table->timestamp('last_refreshed_at')->nullable()->after('last_close');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->dropColumn(['last_close', 'last_refreshed_at']);
        });
    }
};
