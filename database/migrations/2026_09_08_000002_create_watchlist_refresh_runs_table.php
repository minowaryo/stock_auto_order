<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * See docs/architecture/data-model.md#watchlist_refresh_runs (UC-012 /
     * F-012 / ADR-0013 D5, Gate 3承認済み 2026-09-08).
     *
     * 「一括更新」ボタン / `watchlist:refresh` コマンドからキュー投入された
     * 更新ジョブの進捗。画面が wire:poll で読み「更新中 42/150」の進捗表示に
     * 使う。`status IN ('queued','processing')` の行がある間は新規のキュー投入
     * を受け付けない（UC-012 二重実行防止）。
     */
    public function up(): void
    {
        Schema::create('watchlist_refresh_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watchlist_refresh_runs');
    }
};
