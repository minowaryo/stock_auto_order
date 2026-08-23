<?php

namespace App\Services\Sector;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use Illuminate\Support\Collection;

/**
 * UC-005 (セクター配分ダッシュボード): groups the latest snapshot's holdings
 * (all instrument_type, unlike NewCandidateFinder's stock-only scope) by
 * sector_classification_id (null -> "未分類") and computes each sector's
 * allocation_rate / allocation_status, plus a taxable-account-only sell
 * suggestion for overweight (偏り警告) sectors. Mirrors
 * NewCandidateFinder::portfolioEvaluationTotal() for evaluation totals and
 * ShowSignalListAction's holding_snapshot_accounts taxable-fallback pattern
 * for the sell suggestion.
 */
class SectorAllocationCalculator
{
    private const HEALTHY_THRESHOLD = 40.0;

    private const OVERWEIGHT_THRESHOLD = 70.0;

    private const UNCLASSIFIED_NAME = '未分類';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calculate(): array
    {
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        $holdingSnapshots = $latestSnapshot
            ? HoldingSnapshot::query()
                ->where('snapshot_id', $latestSnapshot->id)
                ->with(['holding.sectorClassification', 'accounts'])
                ->get()
            : collect();

        if ($holdingSnapshots->isEmpty()) {
            return [];
        }

        $portfolioTotal = $this->evaluationTotal($holdingSnapshots);

        $groups = $holdingSnapshots->groupBy(
            fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->holding->sector_classification_id ?? 'unclassified'
        );

        return $groups
            ->map(fn (Collection $group) => $this->toSectorRow($group, $portfolioTotal))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, HoldingSnapshot>  $group
     * @return array<string, mixed>
     */
    private function toSectorRow(Collection $group, float $portfolioTotal): array
    {
        $sectorName = $group->first()->holding->sectorClassification?->name ?? self::UNCLASSIFIED_NAME;

        $sectorTotal = $this->evaluationTotal($group);
        $allocationRate = $portfolioTotal > 0 ? ($sectorTotal / $portfolioTotal) * 100 : 0.0;
        $status = $this->status($allocationRate);
        $isOverweight = $status === '偏り警告';

        $suggestedSellAmount = null;
        $suggestedSellQuantity = null;

        if ($isOverweight) {
            $suggestedSellAmount = (($allocationRate - self::OVERWEIGHT_THRESHOLD) / 100) * $portfolioTotal;

            $taxable = $this->taxableAmountAndQuantity($group);

            if ($taxable['amount'] <= 0) {
                $suggestedSellAmount = 0.0;
            }

            $suggestedSellQuantity = $suggestedSellAmount > 0
                ? $suggestedSellAmount / ($taxable['amount'] / $taxable['quantity'])
                : 0.0;
        }

        return [
            'sector_name' => $sectorName,
            'allocation_rate' => $allocationRate,
            'allocation_status' => $status,
            'is_overweight' => $isOverweight,
            'suggested_sell_amount' => $suggestedSellAmount,
            'suggested_sell_quantity' => $suggestedSellQuantity,
        ];
    }

    private function status(float $allocationRate): string
    {
        if ($allocationRate >= self::OVERWEIGHT_THRESHOLD) {
            return '偏り警告';
        }

        if ($allocationRate >= self::HEALTHY_THRESHOLD) {
            return 'やや偏り';
        }

        return '健全';
    }

    /**
     * Sum of taxable (specific/general) evaluation amount and quantity within
     * the sector group. Holdings with no holding_snapshot_accounts breakdown
     * at all fall back to treating the entire quantity as taxable (same
     * convention as ShowSignalListAction::splitLimitSuggestion()).
     *
     * @param  Collection<int, HoldingSnapshot>  $group
     * @return array{amount: float, quantity: float}
     */
    private function taxableAmountAndQuantity(Collection $group): array
    {
        $amount = 0.0;
        $quantity = 0.0;

        foreach ($group as $holdingSnapshot) {
            $accounts = $holdingSnapshot->accounts;
            $taxableQuantity = $accounts->isEmpty()
                ? (float) $holdingSnapshot->quantity
                : (float) $accounts->whereIn('account_type', ['specific', 'general'])->sum('quantity');

            if ($taxableQuantity <= 0) {
                continue;
            }

            $amount += $taxableQuantity * (float) $holdingSnapshot->current_price;
            $quantity += $taxableQuantity;
        }

        return ['amount' => $amount, 'quantity' => $quantity];
    }

    /**
     * @param  Collection<int, HoldingSnapshot>  $holdingSnapshots
     */
    private function evaluationTotal(Collection $holdingSnapshots): float
    {
        return (float) $holdingSnapshots->sum(function (HoldingSnapshot $holdingSnapshot) {
            $value = (float) $holdingSnapshot->quantity * (float) $holdingSnapshot->current_price;

            if ($holdingSnapshot->holding->instrument_type === 'mutual_fund') {
                $value /= 10000;
            }

            return $value;
        });
    }
}
