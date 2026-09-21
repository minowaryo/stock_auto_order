<?php

namespace App\Actions\Signal;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\LossReviewThresholds;
use App\Services\Analysis\SignalCriteriaEvaluator;
use App\Services\Portfolio\ContinuousHoldingWeeksCalculator;
use App\Services\Portfolio\PortfolioEvaluationCalculator;

/**
 * UC-011 / F-011 (整理検討〔含み損〕候補一覧, CHG-0010 / ADR-0010): lists
 * individual stocks from the most recent weekly snapshot whose
 * unrealized_gain_rate is below the 整理検討ライン, together with the numbers
 * that help the user "踏ん切りをつける" (復帰に必要な上昇率・損失実額・推定
 * 保有週数・財務健全性・整理判断チェックリスト).
 *
 * Unlike UC-010 (ShowBuySignalListAction) this list does NOT exclude
 * fundamental-failed / fundamental-unavailable holdings, does NOT exclude
 * holdings that also have a buy_signals row, and applies no NISA filter
 * (ADR-0010 D4 / D7). Display layer only — no DB writes, no schema change.
 */
class ShowLossReviewListAction
{
    public function __construct(
        private readonly FundamentalHealthEvaluator $evaluator,
        private readonly PortfolioEvaluationCalculator $portfolioEvaluationCalculator,
        private readonly SignalCriteriaEvaluator $criteriaEvaluator,
        private readonly ContinuousHoldingWeeksCalculator $continuousHoldingWeeksCalculator,
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

        $allSnapshotIdsNewestFirst = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        $allHoldingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot->id)
            ->with(['holding.fundamentalIndicator', 'holding.technicalIndicator', 'buySignals'])
            ->get();

        $portfolioTotal = $this->portfolioEvaluationCalculator->total($allHoldingSnapshots);

        $targets = $allHoldingSnapshots
            ->filter(fn (HoldingSnapshot $holdingSnapshot) => $this->isTarget($holdingSnapshot))
            ->values();

        if ($targets->isEmpty()) {
            return [];
        }

        $presenceByHolding = HoldingSnapshot::query()
            ->whereIn('holding_id', $targets->pluck('holding_id')->all())
            ->get(['holding_id', 'snapshot_id'])
            ->groupBy('holding_id')
            ->map(fn ($rows) => $rows->pluck('snapshot_id')->all());

        $rows = $targets
            ->map(fn (HoldingSnapshot $holdingSnapshot) => $this->toRow(
                $holdingSnapshot,
                $portfolioTotal,
                $allSnapshotIdsNewestFirst,
                $presenceByHolding->get($holdingSnapshot->holding_id, []),
            ))
            ->all();

        usort($rows, fn (array $a, array $b) => $this->compareRows($a, $b));

        return array_map(function (array $row) {
            unset($row['_fundamental_rank'], $row['_technical_met']);

            return $row;
        }, $rows);
    }

    private function isTarget(HoldingSnapshot $holdingSnapshot): bool
    {
        if ($holdingSnapshot->holding->instrument_type !== 'stock') {
            return false;
        }

        return (float) $holdingSnapshot->unrealized_gain_rate < LossReviewThresholds::LOSS_REVIEW_LINE;
    }

    /**
     * @param  array<int>  $allSnapshotIdsNewestFirst
     * @param  array<int>  $presentSnapshotIds
     * @return array<string, mixed>
     */
    private function toRow(
        HoldingSnapshot $holdingSnapshot,
        float $portfolioTotal,
        array $allSnapshotIdsNewestFirst,
        array $presentSnapshotIds,
    ): array {
        $holding = $holdingSnapshot->holding;
        $fundamentalIndicator = $holding->fundamentalIndicator;
        $technicalIndicator = $holding->technicalIndicator;

        [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth] = $fundamentalIndicator?->healthEvaluatorArgs()
            ?? [null, null, null, null, null, null, null];

        $fundamentalStatus = $this->evaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth);

        $unrealizedGainRate = (float) $holdingSnapshot->unrealized_gain_rate;
        $unrealizedGainAmount = (float) $holdingSnapshot->unrealized_gain_amount;

        $reboundCount = $holdingSnapshot->buySignals->count();
        $reboundPresent = $reboundCount >= 1;
        // ShowBuySignalListAction は財務 failed の銘柄を買い増し候補から除外
        // する（fundamental フィルタは表示時適用）。したがって「買い増し候補
        // にも掲載」と言えるのは failed 以外のときのみ（/review 指摘、CHG-0010）。
        $alsoOnBuyList = $reboundPresent && $fundamentalStatus !== 'failed';

        $continuous = $this->continuousHoldingWeeksCalculator->calculate($allSnapshotIdsNewestFirst, $presentSnapshotIds);

        $criteria = $this->criteriaEvaluator->evaluateLossReview([
            'unrealized_gain_rate' => $unrealizedGainRate,
            // US株は current_price が円換算済み・technical_indicators は USD
            // のため、乖離チップの計算前に USD へ割り戻す（CHG-0010 で
            // UC-004/UC-010 と横断修正）。
            'current_price' => SignalCriteriaEvaluator::indicatorComparablePrice(
                $holdingSnapshot->current_price !== null ? (float) $holdingSnapshot->current_price : null,
                $holdingSnapshot->fx_rate_used !== null ? (float) $holdingSnapshot->fx_rate_used : null,
            ),
            'week52_high' => $technicalIndicator?->week52_high !== null ? (float) $technicalIndicator->week52_high : null,
            'week52_low' => $technicalIndicator?->week52_low !== null ? (float) $technicalIndicator->week52_low : null,
            'ma75' => $technicalIndicator?->ma75 !== null ? (float) $technicalIndicator->ma75 : null,
            'macd' => $technicalIndicator?->macd !== null ? (float) $technicalIndicator->macd : null,
            'macd_signal' => $technicalIndicator?->macd_signal !== null ? (float) $technicalIndicator->macd_signal : null,
            'relative_strength_vs_market' => $technicalIndicator?->relative_strength_vs_market !== null ? (float) $technicalIndicator->relative_strength_vs_market : null,
            // ADR-0015 D4（2回目の/reviewでチェックリスト側の未配線を発見）:
            // BuySignalDeterminationService::preconditionsSatisfied()は対セクター
            // 相対力を優先し対市場へフォールバックするため、このチェックリスト
            // 行も同じ優先順位で判定できるよう両方渡す。
            'relative_strength_vs_sector' => $technicalIndicator?->relative_strength_vs_sector !== null ? (float) $technicalIndicator->relative_strength_vs_sector : null,
            'rebound_buy_signal_count' => (float) $reboundCount,
            'roe' => $roe,
            'equity_ratio' => $equityRatio,
            'revenue_growth' => $revenueGrowth,
            'operating_income_growth' => $operatingIncomeGrowth,
            'operating_margin' => $operatingMargin,
        ]);

        $portfolioLossShare = $portfolioTotal > 0.0
            ? abs($unrealizedGainAmount) / $portfolioTotal * 100
            : null;

        // r = -100%（上場廃止・売買停止等で current_price が 0 取込）のとき
        // 1 + r/100 = 0 で 0 除算になる。復帰に必要な上昇率は事実上無限大の
        // ため null（画面は「算出不可」表示）とする（/review 指摘、CHG-0010）。
        $recoveryDivisor = 1 + $unrealizedGainRate / 100;
        $recoveryRequiredRate = $recoveryDivisor > 0.0
            ? (1 / $recoveryDivisor - 1) * 100
            : null;

        return [
            'id' => $holding->id,
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'unrealized_gain_rate' => $unrealizedGainRate,
            'unrealized_gain_amount' => $unrealizedGainAmount,
            'recovery_required_rate' => $recoveryRequiredRate,
            'portfolio_loss_share' => $portfolioLossShare,
            'continuous_holding_weeks' => $continuous['weeks'],
            'continuous_holding_weeks_is_truncated' => $continuous['is_truncated'],
            'fundamental_status' => $fundamentalStatus,
            'fundamental_summary' => $this->fundamentalSummary($fundamentalStatus, $roe, $equityRatio, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin),
            'rebound_buy_signal_count' => $reboundCount,
            'rebound_buy_signal_present' => $reboundPresent,
            'also_on_buy_list' => $alsoOnBuyList,
            'loss_review_reason_summary' => $this->reasonSummary($unrealizedGainRate, $criteria, $fundamentalStatus, $reboundPresent, $alsoOnBuyList),
            'criteria' => $criteria,
            '_fundamental_rank' => match ($fundamentalStatus) {
                'failed' => 0,
                'unavailable' => 1,
                default => 2,
            },
            '_technical_met' => $criteria['summary']['technical']['met'],
        ];
    }

    /**
     * 並び順（透明マルチキー、ADR-0010 D8）:
     * ①押し目なし→あり ②failed→unavailable→passed ③テクニカル met 数の多い順
     * ④含み損率の深い順.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareRows(array $a, array $b): int
    {
        return ((int) $a['rebound_buy_signal_present'] <=> (int) $b['rebound_buy_signal_present'])
            ?: ($a['_fundamental_rank'] <=> $b['_fundamental_rank'])
            ?: ($b['_technical_met'] <=> $a['_technical_met'])
            ?: ((float) $a['unrealized_gain_rate'] <=> (float) $b['unrealized_gain_rate']);
    }

    private function fundamentalSummary(
        string $fundamentalStatus,
        ?float $roe,
        ?float $equityRatio,
        ?float $revenueGrowth,
        ?float $operatingIncomeGrowth,
        ?float $operatingMargin,
    ): string {
        if ($fundamentalStatus === 'unavailable') {
            return 'ファンダメンタルズ指標が未取得のため判定できません';
        }

        $growth = SignalCriteriaEvaluator::higherGrowthRate($revenueGrowth, $operatingIncomeGrowth);

        $roeNote = ($roe !== null && $roe < FundamentalHealthEvaluator::MIN_ROE) ? '（基準10%未満）' : '';
        $equityNote = ($equityRatio !== null && $equityRatio < FundamentalHealthEvaluator::MIN_EQUITY_RATIO) ? '（基準40%未満）' : '';
        // 判定チェックリストの成長率チップと同じ「≤0%」基準。ちょうど0%も
        // 含むため「マイナス」ではなく「0%以下」と表記する（/review LOW 指摘）。
        $growthNote = ($growth !== null && $growth <= FundamentalHealthEvaluator::MIN_GROWTH_RATE) ? '（成長率0%以下）' : '';
        // 営業利益率とROEは注記の文言・閾値がどちらも「（基準10%未満）」で
        // 同じになるため、必ず指標名とセットで並べる（CHG-0012 / ADR-0011）。
        $marginNote = ($operatingMargin !== null && $operatingMargin < FundamentalHealthEvaluator::MIN_OPERATING_MARGIN) ? '（基準10%未満）' : '';

        return sprintf(
            'ROE%s%%%s・自己資本比率%s%%%s・成長率%s%s・営業利益率%s%%%s',
            $this->fmt($roe),
            $roeNote,
            $this->fmt($equityRatio),
            $equityNote,
            $growth === null ? '-' : sprintf('%+.1f%%', $growth),
            $growthNote,
            $this->fmt($operatingMargin),
            $marginNote,
        );
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function reasonSummary(
        float $unrealizedGainRate,
        array $criteria,
        string $fundamentalStatus,
        bool $reboundPresent,
        bool $alsoOnBuyList,
    ): string {
        $parts = [sprintf('含み損%+.1f%%', $unrealizedGainRate)];

        $metLabels = [];
        foreach ($criteria['technical'] as $item) {
            if ($item['status'] !== 'met') {
                continue;
            }

            if (in_array($item['label'], ['含み損率', '押し目買いシグナル件数'], true)) {
                continue;
            }

            $metLabels[] = $item['label'];
        }

        if ($metLabels !== []) {
            $parts[] = implode('・', $metLabels).'が整理ラインに達しています';
        }

        if ($fundamentalStatus === 'failed') {
            $parts[] = '財務健全性も基準割れ';
        } elseif ($fundamentalStatus === 'passed') {
            $parts[] = '財務健全性は基準を維持';
        } else {
            $parts[] = '財務健全性は判定不可';
        }

        if ($alsoOnBuyList) {
            $parts[] = '押し目買いシグナル発生中・買い増し候補にも掲載';
        } elseif ($reboundPresent) {
            $parts[] = '押し目買いシグナルは出ているが財務基準割れのため買い増し候補には非掲載';
        } else {
            $parts[] = '反発の兆しは出ていません';
        }

        return implode('、', $parts).'。';
    }

    /**
     * 判定チェックリストのチップ（number_format($v, 1)）と整形を揃える
     * （/review 指摘、CHG-0010）。
     */
    private function fmt(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 1);
    }
}
