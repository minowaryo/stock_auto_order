<?php

namespace App\Console\Commands;

use App\Models\FundamentalIndicator;
use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\UsFundamentalIndicatorMapper;
use App\Services\MarketData\FinnhubClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CHG-0011 / ADR-0009 の運用化: 最新スナップショットに含まれる米国個別株に
 * ついて Finnhub からファンダメンタルズ指標を取得し `fundamental_indicators`
 * を補完する。
 *
 * ADR-0009 で FinnhubClient / UsFundamentalIndicatorMapper /
 * FetchExternalMarketDataAction の US 分岐は実装済みだが、その分岐は CSV 取込
 * （ImportCsvAction）時にしか走らない。既存の取込済みスナップショットの米国株
 * を、CSV を取り込み直さずに補完するための単独コマンド。
 *
 * 取得ロジック（fetchMetrics + fetchReportedFinancials →
 * UsFundamentalIndicatorMapper::map → FundamentalIndicator::updateOrCreate）は
 * FetchExternalMarketDataAction の US 分岐と同一。canonical は
 * FetchExternalMarketDataAction であり、一部重複を許容する（本コマンドは
 * FetchExternalMarketDataAction 本体を変更しないためのもの）。
 *
 * 銘柄単位の取得失敗はログに記録してスキップし、その銘柄は既存の
 * `unavailable` 表示にフォールバックする（新しい失敗モードを増やさない、
 * ADR-0009 D5）。
 */
class RefetchUsFundamentalsCommand extends Command
{
    protected $signature = 'market-data:refetch-us-fundamentals';

    protected $description = '最新スナップショットの米国株について Finnhub からファンダメンタルズ指標を取得し fundamental_indicators を補完する';

    public function handle(FinnhubClientInterface $finnhubClient, UsFundamentalIndicatorMapper $mapper): int
    {
        // docs/architecture/data-model.md#snapshots: "直近" は snapshotted_at、
        // 同一秒のタイブレークは id（ShowSignalListAction と同じ規約）。
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestSnapshot) {
            $this->error('スナップショットが1件も存在しません。先に CSV 取込を行ってください。');

            return self::FAILURE;
        }

        $holdingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot->id)
            ->whereHas('holding', fn ($query) => $query
                ->where('market', 'us')
                ->where('instrument_type', 'stock'))
            ->with('holding')
            ->get();

        if ($holdingSnapshots->isEmpty()) {
            $this->info('最新スナップショットに米国個別株の保有はありません。');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        foreach ($holdingSnapshots as $holdingSnapshot) {
            $holding = $holdingSnapshot->holding;

            try {
                $metrics = $finnhubClient->fetchMetrics($holding->symbol_code) ?? [];
                $reportedFinancials = $finnhubClient->fetchReportedFinancials($holding->symbol_code);

                $fundamental = $mapper->map($metrics, $reportedFinancials);

                FundamentalIndicator::updateOrCreate(
                    ['holding_id' => $holding->id],
                    [...$fundamental, 'fetched_at' => now()],
                );

                $updated++;
                $this->line("  {$holding->symbol_code}: 取得OK");
            } catch (Throwable $e) {
                // MarketData クライアントの例外はリクエストURL/ステータスのみを
                // 持ち、APIキーを含まない（ADR-0009 /review 修正でサニタイズ
                // 済み）。この前提はクライアント実装を変えたら再確認する。
                $failed++;
                Log::warning('RefetchUsFundamentalsCommand: 米国株ファンダ取得に失敗', [
                    'holding_id' => $holding->id,
                    'symbol_code' => $holding->symbol_code,
                    'exception' => $e->getMessage(),
                ]);
                $this->warn("  {$holding->symbol_code}: 取得失敗のためスキップ（{$e->getMessage()}）");
            }
        }

        $this->info("完了: {$updated}件更新 / {$failed}件失敗（失敗分は取得不可表示のまま）");

        return self::SUCCESS;
    }
}
