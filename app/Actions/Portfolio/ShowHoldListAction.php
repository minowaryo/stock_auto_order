<?php

namespace App\Actions\Portfolio;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\SignalCriteriaEvaluator;
use App\Services\Analysis\TakeProfitThresholdEvaluator;
use App\Support\SignalListSort;

/**
 * CHG-0028: 売買シグナル画面のキープ表。ClassifyHoldingsAction の hold バケツを
 * SignalListSort の並び順で返す参照専用Action。
 *
 * CHG-0046: 他3テーブルと同じ指標を出すため、各行に判定チェックリスト
 * （SignalCriteriaEvaluator::evaluateHold）・財務健全性サマリ・要観察の理由を
 * 付け足す。ClassifyHoldingsAction の出力（UC-013 / JSON API）は変えない。
 */
class ShowHoldListAction
{
    public function __construct(
        private readonly ClassifyHoldingsAction $classifyHoldingsAction,
        private readonly SignalCriteriaEvaluator $criteriaEvaluator,
        private readonly FundamentalHealthEvaluator $fundamentalHealthEvaluator,
        private readonly TakeProfitThresholdEvaluator $takeProfitThresholdEvaluator,
    ) {}

    /**
     * CHG-0032: 供給元Actionの出力を取得済みなら渡して二重実行を避ける。
     *
     * @param  array<int, array<string, mixed>>|null  $lossReviewRows
     * @param  array<int, array<string, mixed>>|null  $takeProfitRows
     * @param  array<int, array<string, mixed>>|null  $addOnRows
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $sort = SignalListSort::MARKET_VALUE, ?array $lossReviewRows = null, ?array $takeProfitRows = null, ?array $addOnRows = null): array
    {
        $holdings = collect($this->classifyHoldingsAction->execute($lossReviewRows, $takeProfitRows, $addOnRows)['buckets'])
            ->firstWhere('bucket', 'hold')['holdings'] ?? [];

        // おすすめ順: hold_watch 先頭 → 含み損益率の低い順（ClassifyHoldingsAction と同じ）。
        $recommended = fn (array $a, array $b) => (($b['hold_watch'] ? 1 : 0) <=> ($a['hold_watch'] ? 1 : 0))
            ?: ((float) $a['unrealized_gain_rate'] <=> (float) $b['unrealized_gain_rate']);

        usort($holdings, SignalListSort::comparator($sort, $recommended));

        return $this->enrich($holdings);
    }

    /**
     * @param  array<int, array<string, mixed>>  $holdings
     * @return array<int, array<string, mixed>>
     */
    private function enrich(array $holdings): array
    {
        if ($holdings === []) {
            return [];
        }

        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        $snapshotsByKey = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot?->id)
            ->whereHas('holding', fn ($query) => $query->whereIn('symbol_code', array_column($holdings, 'symbol_code')))
            ->with(['holding.fundamentalIndicator', 'holding.technicalIndicator', 'signals'])
            ->get()
            ->keyBy(fn (HoldingSnapshot $hs) => $hs->holding->market.':'.$hs->holding->symbol_code);

        return array_map(function (array $row) use ($snapshotsByKey) {
            $holdingSnapshot = $snapshotsByKey->get($row['market'].':'.$row['symbol_code']);

            return array_merge($row, $holdingSnapshot === null ? $this->missingDetail() : $this->detail($holdingSnapshot, $row));
        }, $holdings);
    }

    /**
     * 分類と行の拡充の間に取り込みが完了し、最新スナップショットに行が見つからない
     * 場合の既定値（/review 指摘）。画面をエラーにせず全指標を「—」で出す。
     *
     * @return array<string, mixed>
     */
    private function missingDetail(): array
    {
        return [
            'id' => null,
            'criteria' => $this->criteriaEvaluator->evaluateHold([]),
            'fundamental_status' => 'unavailable',
            'fundamental_summary' => 'ファンダメンタルズ指標が未取得のため判定できません',
            'hold_watch_reasons' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function detail(HoldingSnapshot $holdingSnapshot, array $row): array
    {
        $holding = $holdingSnapshot->holding;
        $technicalIndicator = $holding->technicalIndicator;
        $fundamentalIndicator = $holding->fundamentalIndicator;

        [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth] = $fundamentalIndicator?->healthEvaluatorArgs()
            ?? [null, null, null, null, null, null, null];

        $fundamentalStatus = $this->fundamentalHealthEvaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth);

        $float = fn ($value) => $value !== null ? (float) $value : null;

        // 利確検討（ShowSignalListAction::resolveThreshold）と同じ利確ライン。
        $gainLine = $this->takeProfitThresholdEvaluator->evaluate(
            $holdingSnapshot->signals->count(),
            $equityRatio,
            $roe,
            $revenueGrowth,
            $operatingIncomeGrowth,
            $operatingMargin,
            $avgRevenueGrowth,
            $avgOperatingIncomeGrowth,
        );

        $criteria = $this->criteriaEvaluator->evaluateHold([
            'unrealized_gain_rate' => $float($row['unrealized_gain_rate']),
            'gain_line_threshold' => $gainLine['target_gain_rate_threshold'],
            // US株は current_price が円換算済み・technical_indicators は USD の
            // ため、乖離チップの計算前に USD へ割り戻す（CHG-0010）。
            'current_price' => SignalCriteriaEvaluator::indicatorComparablePrice(
                $float($holdingSnapshot->current_price),
                $float($holdingSnapshot->fx_rate_used),
            ),
            'rsi' => $float($technicalIndicator?->rsi),
            'macd' => $float($technicalIndicator?->macd),
            'macd_signal' => $float($technicalIndicator?->macd_signal),
            'ma20' => $float($technicalIndicator?->ma20),
            'week52_high' => $float($technicalIndicator?->week52_high),
            'week52_low' => $float($technicalIndicator?->week52_low),
            'relative_strength_vs_market' => $float($technicalIndicator?->relative_strength_vs_market),
            'relative_strength_vs_sector' => $float($technicalIndicator?->relative_strength_vs_sector),
            'peg_ratio' => $float($fundamentalIndicator?->peg_ratio),
            'per' => $float($fundamentalIndicator?->per),
            'pbr' => $float($fundamentalIndicator?->pbr),
            'roe' => $roe,
            'equity_ratio' => $equityRatio,
            'revenue_growth' => $revenueGrowth,
            'operating_income_growth' => $operatingIncomeGrowth,
            'operating_margin' => $operatingMargin,
        ]);

        return [
            'id' => $holding->id,
            'criteria' => $criteria,
            'fundamental_status' => $fundamentalStatus,
            'fundamental_summary' => $this->fundamentalSummary($fundamentalStatus, $roe, $equityRatio, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin),
            'hold_watch_reasons' => $row['hold_watch'] ? $this->holdWatchReasons($fundamentalStatus, $technicalIndicator, $float($row['unrealized_gain_rate'])) : [],
        ];
    }

    /**
     * ClassifyHoldingsAction::isHoldWatch() の (a)(b)(c) のうち該当したものの表示名。
     *
     * @return list<string>
     */
    private function holdWatchReasons(string $fundamentalStatus, $technicalIndicator, ?float $unrealizedGainRate): array
    {
        $reasons = [];

        if ($fundamentalStatus === 'failed') {
            $reasons[] = '財務健全性 基準割れ';
        }

        // ADR-0015 D4: 対セクター優先・未算出なら対市場（isHoldWatch() と同じ）。
        $relativeStrength = $technicalIndicator?->relative_strength_vs_sector ?? $technicalIndicator?->relative_strength_vs_market;
        if ($relativeStrength !== null && (float) $relativeStrength < 0.0) {
            $reasons[] = '相対力マイナス';
        }

        if ($unrealizedGainRate !== null && $unrealizedGainRate <= ClassifyHoldingsAction::HOLD_WATCH_GAIN_RATE_BUFFER) {
            $reasons[] = sprintf('含み損%d%%以下', (int) ClassifyHoldingsAction::HOLD_WATCH_GAIN_RATE_BUFFER);
        }

        return $reasons;
    }

    /**
     * 整理検討（ShowLossReviewListAction::fundamentalSummary）と同じ4項目併記の形式。
     */
    private function fundamentalSummary(string $fundamentalStatus, ?float $roe, ?float $equityRatio, ?float $revenueGrowth, ?float $operatingIncomeGrowth, ?float $operatingMargin): string
    {
        if ($fundamentalStatus === 'unavailable') {
            return 'ファンダメンタルズ指標が未取得のため判定できません';
        }

        $growth = SignalCriteriaEvaluator::higherGrowthRate($revenueGrowth, $operatingIncomeGrowth);
        $fmt = fn (?float $value) => $value === null ? '-' : number_format($value, 1);

        return sprintf(
            'ROE%s%%%s・自己資本比率%s%%%s・成長率%s%s・営業利益率%s%%%s',
            $fmt($roe),
            ($roe !== null && $roe < FundamentalHealthEvaluator::MIN_ROE) ? '（基準10%未満）' : '',
            $fmt($equityRatio),
            ($equityRatio !== null && $equityRatio < FundamentalHealthEvaluator::MIN_EQUITY_RATIO) ? '（基準40%未満）' : '',
            $growth === null ? '-' : sprintf('%+.1f%%', $growth),
            ($growth !== null && $growth <= FundamentalHealthEvaluator::MIN_GROWTH_RATE) ? '（成長率0%以下）' : '',
            $fmt($operatingMargin),
            ($operatingMargin !== null && $operatingMargin < FundamentalHealthEvaluator::MIN_OPERATING_MARGIN) ? '（基準10%未満）' : '',
        );
    }
}
