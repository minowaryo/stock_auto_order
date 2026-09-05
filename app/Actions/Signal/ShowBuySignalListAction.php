<?php

namespace App\Actions\Signal;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\SignalCriteriaEvaluator;
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
        private readonly SignalCriteriaEvaluator $criteriaEvaluator,
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
            ->with(['holding.fundamentalIndicator', 'holding.technicalIndicator', 'buySignals', 'signals'])
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
            // 判定チェックリスト（CHG-0007）: signal_type/fundamental_statusの
            // 合否とは別に、基準点・実測値・達成状態を並べた表示用データ。
            'criteria' => $this->criteriaEvaluator->evaluateBuy($this->buildCriteriaMetrics(
                $holdingSnapshot,
                $equityRatio,
                $roe,
                $revenueGrowth,
                $operatingIncomeGrowth,
            )),
            '_buy_signal_count' => $buySignals->count(),
        ];
    }

    /**
     * @return array<string, float|null>
     */
    private function buildCriteriaMetrics(
        HoldingSnapshot $holdingSnapshot,
        ?float $equityRatio,
        ?float $roe,
        ?float $revenueGrowth,
        ?float $operatingIncomeGrowth,
    ): array {
        $technicalIndicator = $holdingSnapshot->holding->technicalIndicator;
        $fundamentalIndicator = $holdingSnapshot->holding->fundamentalIndicator;

        return [
            'current_price' => $holdingSnapshot->current_price !== null ? (float) $holdingSnapshot->current_price : null,
            'rsi' => $technicalIndicator?->rsi !== null ? (float) $technicalIndicator->rsi : null,
            'macd' => $technicalIndicator?->macd !== null ? (float) $technicalIndicator->macd : null,
            'macd_signal' => $technicalIndicator?->macd_signal !== null ? (float) $technicalIndicator->macd_signal : null,
            'bb_upper' => $technicalIndicator?->bb_upper !== null ? (float) $technicalIndicator->bb_upper : null,
            'bb_lower' => $technicalIndicator?->bb_lower !== null ? (float) $technicalIndicator->bb_lower : null,
            'ma20' => $technicalIndicator?->ma20 !== null ? (float) $technicalIndicator->ma20 : null,
            'week52_high' => $technicalIndicator?->week52_high !== null ? (float) $technicalIndicator->week52_high : null,
            'week52_low' => $technicalIndicator?->week52_low !== null ? (float) $technicalIndicator->week52_low : null,
            'volume' => $technicalIndicator?->volume !== null ? (float) $technicalIndicator->volume : null,
            'volume_ma20' => $technicalIndicator?->volume_ma20 !== null ? (float) $technicalIndicator->volume_ma20 : null,
            'relative_strength_vs_market' => $technicalIndicator?->relative_strength_vs_market !== null ? (float) $technicalIndicator->relative_strength_vs_market : null,
            'peg_ratio' => $fundamentalIndicator?->peg_ratio !== null ? (float) $fundamentalIndicator->peg_ratio : null,
            'roe' => $roe,
            'equity_ratio' => $equityRatio,
            'revenue_growth' => $revenueGrowth,
            'operating_income_growth' => $operatingIncomeGrowth,
        ];
    }

    private function fundamentalSummary(string $fundamentalStatus, ?float $equityRatio, ?float $roe, ?float $revenueGrowth, ?float $operatingIncomeGrowth): string
    {
        if ($fundamentalStatus === 'unavailable') {
            return 'ファンダメンタルズ指標が未取得のため判定できません';
        }

        // use-cases.md UC-010出力例「ROE15.2%・自己資本比率58.0%・営業利益成長率
        // +12.3%」に合わせ、営業利益成長率を優先して表示する。ただし
        // evaluate()の合格条件は「プラスの成長率がいずれか一方でもあればOK」
        // というOR条件のため、実際にプラスだった方（合格の根拠になった方）を
        // 優先表示する（/review 修正2: 符号を見ずに営業利益成長率を無条件
        // 優先すると、売上高成長率のプラスで合格したのにマイナスの営業利益
        // 成長率が表示されてしまう矛盾が起こり得るバグの再発防止）。
        if ($operatingIncomeGrowth !== null && $operatingIncomeGrowth > 0.0) {
            $growthLabel = '営業利益成長率';
            $growthValue = $operatingIncomeGrowth;
        } elseif ($revenueGrowth !== null && $revenueGrowth > 0.0) {
            $growthLabel = '売上高成長率';
            $growthValue = $revenueGrowth;
        } elseif ($operatingIncomeGrowth !== null) {
            // フォールバック（fundamental_status='passed'である以上通常
            // 発生しないはずだが、念のため既存の優先順位を維持する）。
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
