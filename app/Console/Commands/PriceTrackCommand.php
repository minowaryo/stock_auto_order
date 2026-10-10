<?php

namespace App\Console\Commands;

use App\Actions\PriceTracking\TrackPriceHistoryAction;
use App\Services\PriceTracking\PriceFetchRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * UC-018 (F-017 / ADR-0024 D1・D2) の毎週の価格の追跡を実行する。
 * 売却済み・直近のシグナル発生の銘柄を、期限（+26週）の終点の週が確定して
 * 保存できるまで取得し、日経225・S&P500・ドル円も更新する。何度実行しても
 * よく、保存済みの銘柄は取得しない。
 */
class PriceTrackCommand extends Command
{
    protected $signature = 'price:track
        {--dry-run : 対象数を数えるだけで、取得も保存もしない}
        {--include-unavailable : 取得不能になった銘柄も取得し直す}';

    protected $description = '売却後・シグナル発生後の銘柄と指数・ドル円の週足を追跡する（UC-018 / F-017）';

    public function handle(TrackPriceHistoryAction $action): int
    {
        $lock = Cache::lock(PriceFetchRunner::LOCK, PriceFetchRunner::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('ほかの価格の取得が実行中のため、何もせずに終了します。');

            return self::SUCCESS;
        }

        try {
            return $this->track($action);
        } finally {
            $lock->release();
        }
    }

    private function track(TrackPriceHistoryAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $action->execute($dryRun, (bool) $this->option('include-unavailable'));

        $this->info(sprintf('対象 %d銘柄（追跡中）', $summary->targets));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '銘柄: 保存済みで取得不要 %d / 分割で取り直し %d / 成功 %d / 銘柄なし %d / データなし %d / 失敗 %d（うち今回完了 %d）、指数・ドル円: 成功 %d / 失敗 %d',
            $summary->savedAlready,
            $summary->refetchedForSplits,
            $summary->ok,
            $summary->notFound,
            $summary->empty,
            $summary->failed,
            $summary->completed,
            $summary->indicesOk,
            $summary->indicesFailed,
        ));

        if ($summary->aborted) {
            $this->error('取得失敗が続いたため中断しました。時間を置いて再実行すると、続きから取得します。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
