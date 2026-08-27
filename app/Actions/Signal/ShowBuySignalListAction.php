<?php

namespace App\Actions\Signal;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Portfolio\PortfolioEvaluationCalculator;

/**
 * UC-010 (既存保有株の買い増しタイミングレコメンド): lists holdings from the
 * most recent weekly snapshot that have at least one buy_signals row and
 * pass the fundamental health filter, together with a split buy-down
 * suggestion. Unlike UC-004 (ShowSignalListAction), this list is not gated
 * by unrealized_gain_rate and does not exclude NISA-only holdings
 * (ADR-0007 D5).
 */
class ShowBuySignalListAction
{
    /**
     * suggested_amount = ポートフォリオ評価総額 × 2%
     * (docs/architecture/data-model.md「買い増し追加投資額の目安率」).
     */
    private const SUGGESTED_AMOUNT_RATE = 0.02;

    /**
     * 分割買い下がりの倍率（現在値×1.00／×0.93／×0.85の3段階）.
     */
    private const SPLIT_BUY_DOWN_RATIOS = [1.00, 0.93, 0.85];

    /**
     * NISA推奨追加基準 (data-model.md「買い増し側NISA推奨の追加基準」叩き台:
     * ROE15%以上・自己資本比率50%以上、UC-008 NewCandidateFinderと同一値).
     */
    private const NISA_MIN_EQUITY_RATIO = 50.0;

    private const NISA_MIN_ROE = 15.0;

    public function __construct(
        private readonly FundamentalHealthEvaluator $evaluator,
        private readonly PortfolioEvaluationCalculator $portfolioEvaluationCalculator,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestSnapshot) {
            return [];
        }

        $allHoldingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot->id)
            ->with(['holding.fundamentalIndicator', 'buySignals', 'signals'])
            ->get();

        $portfolioTotal = $this->portfolioEvaluationCalculator->total($allHoldingSnapshots);
        $suggestedAmount = $portfolioTotal * self::SUGGESTED_AMOUNT_RATE;

        $rows = $allHoldingSnapshots
            ->filter(fn (HoldingSnapshot $holdingSnapshot) => $this->isEligible($holdingSnapshot))
            ->map(fn (HoldingSnapshot $holdingSnapshot) => $this->toRow($holdingSnapshot, $suggestedAmount))
            ->filter(fn (?array $row) => $row !== null)
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b) => $this->compareRows($a, $b));

        return array_map(function (array $row) {
            unset($row['_buy_signal_count']);

            return $row;
        }, $rows);
    }

    private function isEligible(HoldingSnapshot $holdingSnapshot): bool
    {
        $holding = $holdingSnapshot->holding;

        if ($holding->instrument_type !== 'stock') {
            return false;
        }

        if ($holdingSnapshot->buySignals->isEmpty()) {
            return false;
        }

        if ($holdingSnapshot->signals->isNotEmpty()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toRow(HoldingSnapshot $holdingSnapshot, float $suggestedAmount): ?array
    {
        $holding = $holdingSnapshot->holding;
        $fundamentalIndicator = $holding->fundamentalIndicator;

        $equityRatio = $fundamentalIndicator?->equity_ratio !== null ? (float) $fundamentalIndicator->equity_ratio : null;
        $roe = $fundamentalIndicator?->roe !== null ? (float) $fundamentalIndicator->roe : null;
        $revenueGrowth = $fundamentalIndicator?->revenue_growth !== null ? (float) $fundamentalIndicator->revenue_growth : null;
        $operatingIncomeGrowth = $fundamentalIndicator?->operating_income_growth !== null ? (float) $fundamentalIndicator->operating_income_growth : null;

        $fundamentalStatus = $this->evaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth);

        if ($fundamentalStatus === 'failed') {
            return null;
        }

        $nisaRecommended = $equityRatio !== null && $roe !== null
            && $equityRatio >= self::NISA_MIN_EQUITY_RATIO && $roe >= self::NISA_MIN_ROE;

        $buySignals = $holdingSnapshot->buySignals;

        return [
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'current_price' => $holdingSnapshot->current_price,
            'unrealized_gain_rate' => $holdingSnapshot->unrealized_gain_rate,
            'buy_signal_types' => $buySignals->pluck('signal_type')->values()->all(),
            'buy_signal_reason_summary' => $buySignals->pluck('reason_summary')->implode('、'),
            'fundamental_status' => $fundamentalStatus,
            'fundamental_summary' => $this->fundamentalSummary($fundamentalStatus, $equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth),
            'nisa_recommended' => $nisaRecommended,
            'nisa_recommended_reason' => $nisaRecommended
                ? sprintf('自己資本比率%s%%・ROE%s%%と財務健全性が高くNISA口座での長期保有に適しています', $this->fmt($equityRatio), $this->fmt($roe))
                : '',
            'suggested_amount' => $suggestedAmount,
            'split_buy_down_suggestion' => $this->splitBuyDownSuggestion($holdingSnapshot, $suggestedAmount),
            '_buy_signal_count' => $buySignals->count(),
        ];
    }

    private function fundamentalSummary(string $fundamentalStatus, ?float $equityRatio, ?float $roe, ?float $revenueGrowth, ?float $operatingIncomeGrowth): string
    {
        if ($fundamentalStatus === 'unavailable') {
            return 'ファンダメンタルズ指標が未取得のため判定できません';
        }

        // use-cases.md UC-010出力例「ROE15.2%・自己資本比率58.0%・営業利益成長率
        // +12.3%」に合わせ、営業利益成長率を優先して表示し、未取得の場合のみ
        // 売上高成長率を表示する（evaluate()のOR条件と同じ優先順位）。
        if ($operatingIncomeGrowth !== null) {
            $growthLabel = '営業利益成長率';
            $growthValue = $operatingIncomeGrowth;
        } else {
            $growthLabel = '売上高成長率';
            $growthValue = $revenueGrowth;
        }

        return sprintf(
            'ROE%s%%・自己資本比率%s%%・%s%s%%',
            $this->fmt($roe),
            $this->fmt($equityRatio),
            $growthLabel,
            $growthValue === null ? '-' : sprintf('%+.1f', $growthValue),
        );
    }

    /**
     * @return array<int, array{price: float, quantity: float}>
     */
    private function splitBuyDownSuggestion(HoldingSnapshot $holdingSnapshot, float $suggestedAmount): array
    {
        $currentPrice = (float) $holdingSnapshot->current_price;

        return array_map(function (float $ratio) use ($currentPrice, $suggestedAmount) {
            $price = $currentPrice * $ratio;

            return [
                'price' => $price,
                'quantity' => $price > 0.0 ? floor($suggestedAmount / $price) : 0.0,
            ];
        }, self::SPLIT_BUY_DOWN_RATIOS);
    }

    /**
     * ソート順（use-cases.md UC-010業務ルール、ADR-0007）: ファンダ状態
     * （passedをunavailableより優先）→買い増しシグナル件数の多い順→
     * 含み益率の低い順。
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareRows(array $a, array $b): int
    {
        $statusRank = fn (string $status) => $status === 'passed' ? 0 : 1;

        $statusComparison = $statusRank($a['fundamental_status']) <=> $statusRank($b['fundamental_status']);

        if ($statusComparison !== 0) {
            return $statusComparison;
        }

        $countComparison = $b['_buy_signal_count'] <=> $a['_buy_signal_count'];

        if ($countComparison !== 0) {
            return $countComparison;
        }

        return (float) $a['unrealized_gain_rate'] <=> (float) $b['unrealized_gain_rate'];
    }

    private function fmt(?float $value): string
    {
        return $value === null ? '-' : (string) round($value, 1);
    }
}
