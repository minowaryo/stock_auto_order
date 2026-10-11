<?php

namespace App\Console\Commands;

use App\Actions\PriceTracking\BackfillPriceHistoryAction;
use App\Services\PriceTracking\PriceFetchRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * UC-018 (F-017 / ADR-0024 D5) の初回の価格一括補完を手動で実行する。
 * 売買履歴に現れた銘柄と日経225・S&P500・ドル円の10年分の週足を取得する。
 * 再実行すると、補完済み・取得不能の銘柄は飛ばし、未完了の銘柄だけを取得する。
 */
class PriceBackfillCommand extends Command
{
    protected $signature = 'price:backfill {--dry-run : 対象数を数えるだけで、取得も保存もしない}';

    protected $description = '売買履歴の銘柄と指数・ドル円の過去10年の週足を一括取得する（UC-018 / F-017）';

    public function handle(BackfillPriceHistoryAction $action): int
    {
        $lock = Cache::lock(PriceFetchRunner::LOCK, PriceFetchRunner::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('ほかの価格の取得が実行中のため、何もせずに終了します。');

            return self::SUCCESS;
        }

        try {
            return $this->backfill($action);
        } finally {
            $lock->release();
        }
    }

    private function backfill(BackfillPriceHistoryAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $action->execute($dryRun);

        if ($dryRun) {
            $this->info(sprintf('対象 %d銘柄（うち補完済み・取得不能で取得しないもの %d）', $summary->targets, $summary->skipped));

            return self::SUCCESS;
        }

        $this->info(sprintf('対象 %d銘柄（うち補完済み・取得不能で取得しないもの %d）', $summary->targets, $summary->skipped));
        $this->info(sprintf(
            '銘柄: 成功 %d / 銘柄なし %d / データなし %d / 失敗 %d、指数・ドル円: 成功 %d / 失敗 %d',
            $summary->ok,
            $summary->notFound,
            $summary->empty,
            $summary->failed,
            $summary->indicesOk,
            $summary->indicesFailed,
        ));

        if ($summary->aborted) {
            $this->error('取得失敗が続いたため中断しました。時間を置いて再実行すると、未完了の銘柄だけを取得します。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
