<?php

namespace App\Actions\Holding;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;

/**
 * UC-002 (保有銘柄一覧表示): lists the holdings recorded in the most recent
 * weekly snapshot, optionally filtered by sector name / signal presence.
 */
class ListHoldingsAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(?string $sector = null, bool $signalOnly = false): array
    {
        // docs/architecture/data-model.md#snapshots: "直近" is determined by
        // snapshotted_at, with id as a tiebreaker for same-second snapshots
        // (same convention as ImportCsvAction).
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestSnapshot) {
            return [];
        }

        $holdingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot->id)
            ->with([
                'holding.sectorClassification',
                'holding.technicalIndicator',
                'holding.fundamentalIndicator',
                'signals',
            ])
            ->get();

        $rows = $holdingSnapshots->map(fn (HoldingSnapshot $holdingSnapshot) => $this->toRow($holdingSnapshot));

        if ($sector !== null) {
            $rows = $rows->where('sector', $sector);
        }

        if ($signalOnly) {
            $rows = $rows->where('has_signal', true);
        }

        return $rows->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(HoldingSnapshot $holdingSnapshot): array
    {
        $holding = $holdingSnapshot->holding;

        return [
            'id' => $holding->id,
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'market' => $holding->market,
            'instrument_type' => $holding->instrument_type,
            'quantity' => $holdingSnapshot->quantity,
            'average_cost' => $holdingSnapshot->average_cost,
            'current_price' => $holdingSnapshot->current_price,
            'unrealized_gain_rate' => $holdingSnapshot->unrealized_gain_rate,
            'sector' => $holding->sectorClassification->name ?? '未分類',
            // UC-002業務ルール: ETF・投資信託はhas_signalを常にfalseとする。
            // signals行はstock以外には作られない想定だが、その前提の成否に
            // 関わらずここでも明示的にガードする。
            'has_signal' => $holding->instrument_type === 'stock' && $holdingSnapshot->signals->isNotEmpty(),
            'rsi' => $holding->technicalIndicator->rsi ?? null,
            'per' => $holding->fundamentalIndicator->per ?? null,
            'revenue_growth' => $holding->fundamentalIndicator->revenue_growth ?? null,
            'is_newly_detected' => $holdingSnapshot->is_newly_detected,
        ];
    }
}
