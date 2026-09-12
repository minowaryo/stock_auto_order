<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#watchlist_buy_signals (UC-012 /
     * F-012 / ADR-0013 D3, Gate 3承認済み 2026-09-08).
     *
     * 未保有のウォッチリスト銘柄について BuySignalDeterminationService が判定
     * した押し目買いシグナル。`buy_signals`（UC-010）とは signal_type の値域が
     * 同一だが、未保有銘柄は holding_snapshots 行を持たないため holding_id を
     * キーにする別テーブルにする（`buy_signals` を独立テーブルにした ADR-0007
     * D2 と同じ判断）。`signals` / `buy_signals` には一切書き込まない。
     */
    public function up(): void
    {
        Schema::create('watchlist_buy_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('holdings');
            $table->enum('signal_type', [
                'rsi_oversold_rebound',
                'macd_golden_cross',
                'bollinger_oversold',
                'week52_low_proximity',
                'ma_deviation_oversold',
                'volume_spike_rebound',
                'peg_undervalued',
            ]);
            $table->string('reason_summary');
            $table->timestamp('determined_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['holding_id', 'signal_type']);
            $table->index('holding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watchlist_buy_signals');
    }
};
