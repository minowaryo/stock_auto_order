<?php

namespace App\Actions\Signal;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\TakeProfitThresholdEvaluator;

/**
 * UC-004 (利確シグナル一覧): lists holdings from the most recent weekly
 * snapshot whose unrealized gain rate exceeds +20% (stocks only), together
 * with their detected signals and a suggested split take-profit plan.
 *
 * CHG-0006: the actual threshold applied per-holding (+20%超 normal /
 * +150%超 high_water_mark) is determined dynamically via
 * TakeProfitThresholdEvaluator; see splitLimitSuggestion() and the
 * post-query filtering in execute().
 */
class ShowSignalListAction
{
    public function __construct(private readonly TakeProfitThresholdEvaluator $takeProfitThresholdEvaluator) {}

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
            ->where(function ($query) {
                // Exclude holdings whose holding_snapshot_accounts breakdown
                // (ADR-0002) shows zero taxable (specific+general) quantity
                // (i.e. entirely NISA). Holdings with no breakdown rows at
                // all (legacy / not-yet-backfilled snapshots) are kept via
                // the backward-compatible fallback (whereDoesntHave).
                $query->whereDoesntHave('accounts')
                    ->orWhereHas('accounts', fn ($accounts) => $accounts
                        ->whereIn('account_type', ['specific', 'general']));
            })
            ->with(['holding', 'holding.fundamentalIndicator', 'signals', 'accounts'])
            ->get();

        return $holdingSnapshots
            ->map(function (HoldingSnapshot $holdingSnapshot) {
                $threshold = $this->resolveThreshold($holdingSnapshot);

                return [$holdingSnapshot, $threshold];
            })
            ->filter(fn (array $pair) => (float) $pair[0]->unrealized_gain_rate > $pair[1]['target_gain_rate_threshold'])
            ->map(fn (array $pair) => $this->toRow($pair[0], $pair[1]))
            ->values()
            ->all();
    }

    /**
     * @return array{mode: string, target_gain_rate_threshold: float, first_tier_price_multiplier: float, second_tier_price_multiplier: float}
     */
    private function resolveThreshold(HoldingSnapshot $holdingSnapshot): array
    {
        $fundamentalIndicator = $holdingSnapshot->holding->fundamentalIndicator;

        $equityRatio = $fundamentalIndicator?->equity_ratio !== null ? (float) $fundamentalIndicator->equity_ratio : null;
        $roe = $fundamentalIndicator?->roe !== null ? (float) $fundamentalIndicator->roe : null;
        $revenueGrowth = $fundamentalIndicator?->revenue_growth !== null ? (float) $fundamentalIndicator->revenue_growth : null;
        $operatingIncomeGrowth = $fundamentalIndicator?->operating_income_growth !== null ? (float) $fundamentalIndicator->operating_income_growth : null;

        return $this->takeProfitThresholdEvaluator->evaluate(
            $holdingSnapshot->signals->count(),
            $equityRatio,
            $roe,
            $revenueGrowth,
            $operatingIncomeGrowth,
        );
    }

    /**
     * @param  array{mode: string, target_gain_rate_threshold: float, first_tier_price_multiplier: float, second_tier_price_multiplier: float}  $threshold
     * @return array<string, mixed>
     */
    private function toRow(HoldingSnapshot $holdingSnapshot, array $threshold): array
    {
        $holding = $holdingSnapshot->holding;
        $signals = $holdingSnapshot->signals;

        $signalReasonSummary = $signals->isEmpty()
            ? '利確検討が必要なシグナルは検出されていません'
            : $signals->pluck('reason_summary')->implode('、');

        if ($threshold['mode'] === 'high_water_mark') {
            $signalReasonSummary .= '（利確ラインを+150%まで引き上げています）';
        }

        return [
            'id' => $holding->id,
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'unrealized_gain_rate' => $holdingSnapshot->unrealized_gain_rate,
            'signal_types' => $signals->pluck('signal_type')->values()->all(),
            'signal_reason_summary' => $signalReasonSummary,
            'split_limit_suggestion' => $this->splitLimitSuggestion($holdingSnapshot, $threshold),
        ];
    }

    /**
     * docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」:
     * +20%地点・+35%地点でそれぞれ保有数量の1/3ずつ指値、残りはトレンド追従
     * （price: null）とする3段階の分割利確案。数量の基準は課税口座
     * （specific/general）分のみ（NISA区分は除外）。ただし
     * holding_snapshot_accounts の内訳が1件も無い場合（内訳未取得）は、
     * 後方互換のため保有数量全体を課税口座扱いとしてフォールバックする。
     * 価格帯（+20%/+35%）はホールディング全体のaverage_cost基準のまま
     * 変更しない。
     *
     * Price bands (+20%/+35% normal, +100%/+150% high_water_mark, per
     * $threshold's multipliers) are based on the whole position's
     * average_cost, unchanged.
     *
     * @param  array{mode: string, target_gain_rate_threshold: float, first_tier_price_multiplier: float, second_tier_price_multiplier: float}  $threshold
     * @return array<int, array{price: float|null, quantity: float}>
     */
    private function splitLimitSuggestion(HoldingSnapshot $holdingSnapshot, array $threshold): array
    {
        $accounts = $holdingSnapshot->accounts;
        $quantity = $accounts->isEmpty()
            ? (float) $holdingSnapshot->quantity
            : (float) $accounts
                ->whereIn('account_type', ['specific', 'general'])
                ->sum('quantity');
        $averageCost = (float) $holdingSnapshot->average_cost;

        $firstTierQuantity = floor($quantity / 3);
        $secondTierQuantity = floor($quantity / 3);
        $remainingQuantity = $quantity - $firstTierQuantity - $secondTierQuantity;

        return [
            ['price' => $averageCost * $threshold['first_tier_price_multiplier'], 'quantity' => $firstTierQuantity],
            ['price' => $averageCost * $threshold['second_tier_price_multiplier'], 'quantity' => $secondTierQuantity],
            ['price' => null, 'quantity' => $remainingQuantity],
        ];
    }
}
