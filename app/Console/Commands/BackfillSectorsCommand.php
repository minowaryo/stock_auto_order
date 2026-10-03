<?php

namespace App\Console\Commands;

use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Models\WatchlistItem;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\Sector\SectorClassificationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CHG-0029 / ADR-0020 D5: 最新スナップショットの保有とウォッチリスト銘柄のうち未分類のものへ、
 * 日本株=J-Quants / 米国株=Finnhub業種 / 投信=固定カテゴリ を一括反映する。
 *
 * 既存の分類は上書きしない（冪等）。銘柄単位の取得失敗はスキップして続行する。
 */
class BackfillSectorsCommand extends Command
{
    protected $signature = 'sectors:backfill';

    protected $description = '最新スナップショットの未分類の保有に、日本株・米国株・投資信託のセクター分類を一括反映する';

    public function handle(
        JQuantsClientInterface $jQuantsClient,
        FinnhubClientInterface $finnhubClient,
        SectorClassificationResolver $resolver,
    ): int {
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestSnapshot) {
            $this->error('スナップショットが1件も存在しません。先に CSV 取込を行ってください。');

            return self::FAILURE;
        }

        // CHG-0044 / ADR-0020 D6: latest holdings plus watchlist symbols. Past
        // holdings that are neither are left alone to bound the API calls.
        $holdings = Holding::query()
            ->whereNull('sector_classification_id')
            ->where(fn ($query) => $query
                ->whereIn('id', HoldingSnapshot::query()->where('snapshot_id', $latestSnapshot->id)->select('holding_id'))
                ->orWhereIn('id', WatchlistItem::query()->select('holding_id')))
            ->get();

        $classified = 0;

        foreach ($holdings as $holding) {
            try {
                $sector = $resolver->classify($holding, $jQuantsClient, $finnhubClient);
            } catch (Throwable) {
                Log::warning('sectors:backfill: sector fetch failed', [
                    'holding_id' => $holding->id,
                    'symbol_code' => $holding->symbol_code,
                ]);

                continue;
            }

            if ($sector === null) {
                continue;
            }

            $holding->forceFill(['sector_classification_id' => $sector->id])->save();
            $classified++;
        }

        $this->info("未分類 {$holdings->count()} 件のうち {$classified} 件を分類しました。");

        return self::SUCCESS;
    }
}
