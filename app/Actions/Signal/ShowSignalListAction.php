<?php

namespace App\Actions\Signal;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;

/**
 * UC-004 (利確シグナル一覧): lists holdings from the most recent weekly
 * snapshot whose unrealized gain rate exceeds +20% (stocks only), together
 * with their detected signals and a suggested split take-profit plan.
 */
class ShowSignalListAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        // docs/architecture/data-model.md#snapshots: "直近" is determined by
        // snapshotted_at, with id as a tiebreaker for same-second snapshots
        // (same convention as ListHoldingsAction).
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestSnapshot) {
            return [];
        }

        $holdingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot->id)
            ->where('unrealized_gain_rate', '>', 20)
            ->whereHas('holding', fn ($query) => $query->where('instrument_type', 'stock'))
            ->with(['holding', 'signals'])
            ->get();

        return $holdingSnapshots
            ->map(fn (HoldingSnapshot $holdingSnapshot) => $this->toRow($holdingSnapshot))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(HoldingSnapshot $holdingSnapshot): array
    {
        $holding = $holdingSnapshot->holding;
        $signals = $holdingSnapshot->signals;

        return [
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'unrealized_gain_rate' => $holdingSnapshot->unrealized_gain_rate,
            'signal_types' => $signals->pluck('signal_type')->values()->all(),
            'signal_reason_summary' => $signals->isEmpty()
                ? '利確検討が必要なシグナルは検出されていません'
                : $signals->pluck('reason_summary')->implode('、'),
            'split_limit_suggestion' => $this->splitLimitSuggestion($holdingSnapshot),
        ];
    }

    /**
     * docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」:
     * +20%地点・+35%地点でそれぞれ保有数量の1/3ずつ指値、残りはトレンド追従
     * （price: null）とする3段階の分割利確案。NISA区分除外は未対応
     * （UC004SignalListTest.php冒頭のScope note参照）のため、保有数量全体
     * を基準に計算する。
     *
     * @return array<int, array{price: float|null, quantity: float}>
     */
    private function splitLimitSuggestion(HoldingSnapshot $holdingSnapshot): array
    {
        $quantity = (float) $holdingSnapshot->quantity;
        $averageCost = (float) $holdingSnapshot->average_cost;

        $firstTierQuantity = floor($quantity / 3);
        $secondTierQuantity = floor($quantity / 3);
        $remainingQuantity = $quantity - $firstTierQuantity - $secondTierQuantity;

        return [
            ['price' => $averageCost * 1.20, 'quantity' => $firstTierQuantity],
            ['price' => $averageCost * 1.35, 'quantity' => $secondTierQuantity],
            ['price' => null, 'quantity' => $remainingQuantity],
        ];
    }
}
