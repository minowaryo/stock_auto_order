<?php

namespace App\Console\Commands;

use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use Illuminate\Console\Command;

/**
 * UC-012 (F-012 / ADR-0013 D5) の一括更新をターミナルから同期実行する。
 * 画面の「一括更新」ボタンはキュー（RefreshWatchlistMarketDataJob）で行うが、
 * キューワーカー不調時の逃げ道・手動運用用に単独コマンドも用意する
 * （RefetchUsFundamentalsCommand と同じ位置づけ）。
 */
class RefreshWatchlistCommand extends Command
{
    protected $signature = 'watchlist:refresh';

    protected $description = '未保有のウォッチリスト銘柄について指標・押し目買いシグナルを取得し直す（UC-012 / F-012）';

    public function handle(RefreshWatchlistMarketDataAction $action): int
    {
        $this->info('ウォッチリストの一括更新を開始します…');

        $run = $action->execute();

        $this->info(sprintf(
            '完了: %d件処理 / %d件失敗（失敗分は取得不可表示のまま）',
            $run->processed_count,
            $run->failed_count,
        ));

        return self::SUCCESS;
    }
}
