<?php

namespace App\Services\Import;

use App\Actions\Import\Support\TradeReconciliationSummary;
use App\Models\HoldingSnapshotAccount;
use App\Models\Snapshot;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Models\TradeReconciliationItem;
use Illuminate\Support\Carbon;

/**
 * Compares the share count reconstructed from the trade history with the
 * latest holdings snapshot, per holding and account type, and appends the
 * result (UC-017 基本フロー4〜5).
 *
 * Only stock / ETF positions of markets jp / us are compared; the trade
 * history does not cover mutual funds.
 */
class TradeHistoryReconciler
{
    private const EPSILON = 0.0001;

    /**
     * Signed effect of each trade kind on the share count.
     */
    private const SIGNS = [
        'buy' => 1,
        'transfer_in' => 1,
        'tsumitate' => 1,
        'split_in' => 1,
        'sell' => -1,
        'transfer_out' => -1,
    ];

    /**
     * Market close per market. The CSVs carry no execution time, so a trade
     * dated D counts as executed before the snapshot only when the snapshot
     * was taken at or after that market's close on D.
     */
    private const MARKET_CLOSE = [
        'jp' => ['Asia/Tokyo', '15:30'],
        'us' => ['America/New_York', '16:00'],
    ];

    public function reconcile(TradeImportBatch $batch): ?TradeReconciliationSummary
    {
        $snapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $history = $this->historyQuantities($snapshot->snapshotted_at);
        $held = $this->snapshotQuantities($snapshot);
        $counts = ['matched' => 0, 'snapshot_only' => 0, 'history_only' => 0, 'needs_review' => 0];
        $rows = [];
        $now = now();

        foreach (array_keys($history + $held) as $key) {
            $historyQuantity = $history[$key] ?? null;
            $snapshotQuantity = $held[$key] ?? null;
            $h = $historyQuantity ?? 0.0;
            $s = $snapshotQuantity ?? 0.0;

            if (abs($h) < self::EPSILON && abs($s) < self::EPSILON) {
                continue;
            }

            if (abs($h) < self::EPSILON) {
                $status = 'snapshot_only';
            } elseif (abs($s) < self::EPSILON) {
                $status = 'history_only';
            } elseif (abs($h - $s) < self::EPSILON) {
                $status = 'matched';
            } else {
                $status = 'needs_review';
            }

            [$holdingId, $accountType] = explode('|', $key);
            $counts[$status]++;
            $rows[] = [
                'trade_import_batch_id' => $batch->id,
                'snapshot_id' => $snapshot->id,
                'holding_id' => (int) $holdingId,
                'account_type' => $accountType,
                'history_quantity' => $historyQuantity,
                'snapshot_quantity' => $snapshotQuantity,
                'status' => $status,
                'reason' => $status === 'needs_review' ? '数量不一致' : null,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TradeReconciliationItem::insert($chunk);
        }

        return new TradeReconciliationSummary($snapshot->id, $counts);
    }

    /**
     * Net share count per "holding_id|account_type" from trades whose
     * market closed on their trade date at or before $snapshotAt, ignoring
     * rows missing from the latest full history.
     *
     * @return array<string, float>
     */
    private function historyQuantities(Carbon $snapshotAt): array
    {
        $quantities = [];

        TradeExecution::query()
            ->select(['holding_id', 'market', 'account_type', 'kind', 'quantity', 'trade_date'])
            ->where('review_status', 'ok')
            // Coarse filter; the per-market close check below is exact.
            ->where('trade_date', '<=', $snapshotAt->toDateString())
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$quantities, $snapshotAt) {
                [$timezone, $time] = self::MARKET_CLOSE[$row->market];

                if (Carbon::parse("{$row->trade_date} {$time}", $timezone)->gt($snapshotAt)) {
                    return;
                }

                $key = "{$row->holding_id}|{$row->account_type}";
                $quantities[$key] = ($quantities[$key] ?? 0.0) + self::SIGNS[$row->kind] * (float) $row->quantity;
            });

        return $quantities;
    }

    /**
     * Share count per "holding_id|account_type" in the snapshot, stock / ETF
     * positions of markets jp / us only.
     *
     * @return array<string, float>
     */
    private function snapshotQuantities(Snapshot $snapshot): array
    {
        return HoldingSnapshotAccount::query()
            ->join('holding_snapshots', 'holding_snapshots.id', '=', 'holding_snapshot_accounts.holding_snapshot_id')
            ->join('holdings', 'holdings.id', '=', 'holding_snapshots.holding_id')
            ->where('holding_snapshots.snapshot_id', $snapshot->id)
            ->whereIn('holdings.market', ['jp', 'us'])
            ->select(['holding_snapshots.holding_id', 'holding_snapshot_accounts.account_type', 'holding_snapshot_accounts.quantity'])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => ["{$row->holding_id}|{$row->account_type}" => (float) $row->quantity])
            ->all();
    }
}
