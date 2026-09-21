<?php

namespace App\Actions\Watchlist;

use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Models\WatchlistItem;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\SignalCriteriaEvaluator;
use App\Services\Portfolio\PortfolioEvaluationCalculator;
use App\Services\Sector\SectorAllocationCalculator;
use Illuminate\Support\Collection;

/**
 * UC-012「基本フロー（一覧の閲覧）」(F-012 / ADR-0013 D4): builds the unheld
 * watchlist candidate list for the刷新した「新規投資候補」画面.
 *
 * - Rows = watchlist_items whose holding is NOT in the latest snapshot's
 *   holding_snapshots (held favorites drop off automatically).
 * - Ranked by a transparent multi-key sort (NOT filtered, NOT a composite
 *   score — same stance as ADR-0010 D8): ①押し目買いシグナル件数 desc,
 *   ②財務健全性 passed→unavailable→failed, ③同セクター保有比率 asc,
 *   ④52週レンジ内位置 asc.
 * - Reuses FundamentalHealthEvaluator (合否), SignalCriteriaEvaluator::evaluateBuy
 *   (判定チェックリスト, same as UC-010), and SectorAllocationCalculator
 *   (overlap_rate, same formula as UC-006). No new calculation is introduced.
 */
class ShowWatchlistAction
{
    /** suggested_amount = 保有評価額合計 × 1% (UC-008 と同一). */
    private const SUGGESTED_AMOUNT_RATE = 0.01;

    /** NISA推奨の追加基準 (UC-008 と同一). */
    private const NISA_MIN_EQUITY_RATIO = 50.0;

    private const NISA_MIN_ROE = 15.0;

    private const UNCLASSIFIED_NAME = '未分類';

    private const FUNDAMENTAL_RANK = ['passed' => 0, 'unavailable' => 1, 'failed' => 2];

    public function __construct(
        private readonly FundamentalHealthEvaluator $evaluator,
        private readonly SignalCriteriaEvaluator $criteriaEvaluator,
        private readonly SectorAllocationCalculator $sectorAllocationCalculator,
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

        $heldSnapshots = $latestSnapshot
            ? HoldingSnapshot::query()->where('snapshot_id', $latestSnapshot->id)->with('holding')->get()
            : collect();

        $heldHoldingIds = $heldSnapshots->pluck('holding_id')->all();
        $suggestedAmount = $this->portfolioEvaluationCalculator->total($heldSnapshots) * self::SUGGESTED_AMOUNT_RATE;

        // Sector allocations computed once (avoids an N+1 of
        // SectorAllocationCalculator::calculate() per row).
        $sectorAllocations = collect($this->sectorAllocationCalculator->calculate())->keyBy('sector_name');

        $items = WatchlistItem::query()
            ->whereNotIn('holding_id', $heldHoldingIds)
            ->with(['holding.sectorClassification', 'holding.technicalIndicator', 'holding.fundamentalIndicator', 'watchlistBuySignals'])
            ->get();

        $rows = $items
            ->map(fn (WatchlistItem $item) => $this->toRow($item, $sectorAllocations, $suggestedAmount))
            ->all();

        usort($rows, function (array $a, array $b) {
            return [$b['rebound_buy_signal_count'], self::FUNDAMENTAL_RANK[$a['fundamental_status']], $a['overlap_rate'], $a['_range_sort']]
                <=> [$a['rebound_buy_signal_count'], self::FUNDAMENTAL_RANK[$b['fundamental_status']], $b['overlap_rate'], $b['_range_sort']];
        });

        return $rows;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $sectorAllocations
     * @return array<string, mixed>
     */
    private function toRow(WatchlistItem $item, $sectorAllocations, float $suggestedAmount): array
    {
        $holding = $item->holding;
        $technical = $holding->technicalIndicator;
        $fundamental = $holding->fundamentalIndicator;

        [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth] = $fundamental?->healthEvaluatorArgs()
            ?? [null, null, null, null, null, null, null];

        $fundamentalStatus = $this->evaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth);

        $currentPrice = $item->last_close !== null ? (float) $item->last_close : null;
        $week52High = $technical?->week52_high !== null ? (float) $technical->week52_high : null;
        $week52Low = $technical?->week52_low !== null ? (float) $technical->week52_low : null;
        $rangePosition = $this->week52RangePosition($currentPrice, $week52High, $week52Low);

        [$overlapRate, $diversificationComment] = $this->overlap($holding->sectorClassification?->name ?? self::UNCLASSIFIED_NAME, $sectorAllocations);

        $nisaRecommended = $equityRatio !== null && $roe !== null
            && $equityRatio >= self::NISA_MIN_EQUITY_RATIO && $roe >= self::NISA_MIN_ROE;

        return [
            'watchlist_item_id' => $item->id,
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'market' => $holding->market,
            'folder_name' => $item->folder_name,
            'is_starred' => $item->is_starred,
            'in_rakuten_favorites' => $item->source === 'rakuten_favorites_csv',
            'current_price' => $currentPrice,
            'week52_range_position' => $rangePosition,
            'overlap_rate' => $overlapRate,
            'diversification_comment' => $diversificationComment,
            'rebound_buy_signal_count' => $item->watchlistBuySignals->count(),
            'rebound_buy_signal_types' => $item->watchlistBuySignals->pluck('signal_type')->values()->all(),
            'fundamental_status' => $fundamentalStatus,
            'fundamental_summary' => $this->fundamentalSummary($fundamentalStatus, $equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth),
            'suggested_amount' => $suggestedAmount,
            'nisa_recommended' => $nisaRecommended,
            'rsi' => $technical?->rsi,
            'per' => $fundamental?->per,
            'pbr' => $fundamental?->pbr,
            'roe' => $roe,
            'equity_ratio' => $equityRatio,
            'operating_margin' => $operatingMargin,
            'criteria' => $this->criteriaEvaluator->evaluateBuy([
                'current_price' => $currentPrice,
                'rsi' => $technical?->rsi !== null ? (float) $technical->rsi : null,
                'macd' => $technical?->macd !== null ? (float) $technical->macd : null,
                'macd_signal' => $technical?->macd_signal !== null ? (float) $technical->macd_signal : null,
                'bb_upper' => $technical?->bb_upper !== null ? (float) $technical->bb_upper : null,
                'bb_lower' => $technical?->bb_lower !== null ? (float) $technical->bb_lower : null,
                'ma20' => $technical?->ma20 !== null ? (float) $technical->ma20 : null,
                'week52_high' => $week52High,
                'week52_low' => $week52Low,
                'volume' => $technical?->volume !== null ? (float) $technical->volume : null,
                'volume_ma20' => $technical?->volume_ma20 !== null ? (float) $technical->volume_ma20 : null,
                'relative_strength_vs_market' => $technical?->relative_strength_vs_market !== null ? (float) $technical->relative_strength_vs_market : null,
                'peg_ratio' => $fundamental?->peg_ratio !== null ? (float) $fundamental->peg_ratio : null,
                'roe' => $roe,
                'equity_ratio' => $equityRatio,
                'revenue_growth' => $revenueGrowth,
                'operating_income_growth' => $operatingIncomeGrowth,
                'operating_margin' => $operatingMargin,
            ]),
            // sort helper: nulls (no 52w range) go last
            '_range_sort' => $rangePosition ?? 2.0,
        ];
    }

    private function week52RangePosition(?float $current, ?float $high, ?float $low): ?float
    {
        if ($current === null || $high === null || $low === null || $high <= $low) {
            return null;
        }

        return ($current - $low) / ($high - $low);
    }

    /**
     * Mirrors App\Services\Candidate\CandidateOverlapCalculator (UC-006) but
     * against a pre-computed sector-allocation map (no per-row DB hit).
     *
     * @param  Collection<string, array<string, mixed>>  $sectorAllocations
     * @return array{0: float, 1: string}
     */
    private function overlap(string $sectorName, $sectorAllocations): array
    {
        $row = $sectorAllocations->get($sectorName);

        if ($row === null) {
            return [0.0, '現在このセクターの保有はありません。新規投資は分散に貢献します'];
        }

        $comment = match ($row['allocation_status']) {
            '健全' => 'このセクターへの追加投資は分散の観点で問題ありません',
            'やや偏り' => 'このセクターの保有比率はやや高めです。追加投資は慎重に検討してください',
            '偏り警告' => 'このセクターの保有比率は既に高い状態です。新規投資は分散の観点で推奨されません',
            default => '現在このセクターの保有はありません。新規投資は分散に貢献します',
        };

        return [(float) $row['allocation_rate'], $comment];
    }

    private function fundamentalSummary(
        string $status,
        ?float $equityRatio,
        ?float $roe,
        ?float $revenueGrowth,
        ?float $operatingIncomeGrowth,
        ?float $operatingMargin,
        ?float $avgRevenueGrowth = null,
        ?float $avgOperatingIncomeGrowth = null,
    ): string {
        if ($status === 'unavailable') {
            return 'ファンダメンタルズ指標が未取得のため判定できません';
        }

        // ADR-0015 D1/D2レスキューの合格根拠を正しく表示する
        // （app/Actions/Signal/ShowBuySignalListAction::fundamentalSummary()
        // と同じロジック、/review 3回目の指摘・Cycle6で両方修正）。
        if ($roe !== null && $equityRatio !== null
            && ! ($operatingIncomeGrowth !== null && $operatingIncomeGrowth > 0.0)
            && ! ($revenueGrowth !== null && $revenueGrowth > 0.0)
            && ! ($avgOperatingIncomeGrowth !== null && $avgOperatingIncomeGrowth > 0.0)
            && ! ($avgRevenueGrowth !== null && $avgRevenueGrowth > 0.0)
            && $roe >= FundamentalHealthEvaluator::RESCUE_MIN_ROE
            && $equityRatio >= FundamentalHealthEvaluator::RESCUE_MIN_EQUITY_RATIO) {
            return sprintf(
                'ROE%s%%・自己資本比率%s%%と財務健全性が高いため合格しています（成長率は基準を満たしていません）',
                $this->fmt($roe),
                $this->fmt($equityRatio),
            );
        }

        $growth = match (true) {
            $operatingIncomeGrowth !== null && $operatingIncomeGrowth > 0.0 => ['営業利益成長率', $operatingIncomeGrowth],
            $revenueGrowth !== null && $revenueGrowth > 0.0 => ['売上高成長率', $revenueGrowth],
            $avgOperatingIncomeGrowth !== null && $avgOperatingIncomeGrowth > 0.0 => ['3期平均営業利益成長率', $avgOperatingIncomeGrowth],
            $avgRevenueGrowth !== null && $avgRevenueGrowth > 0.0 => ['3期平均売上高成長率', $avgRevenueGrowth],
            $operatingIncomeGrowth !== null => ['営業利益成長率', $operatingIncomeGrowth],
            default => ['売上高成長率', $revenueGrowth],
        };

        return sprintf(
            'ROE%s%%・自己資本比率%s%%・%s%s%%・営業利益率%s%%',
            $this->fmt($roe),
            $this->fmt($equityRatio),
            $growth[0],
            $growth[1] === null ? '-' : sprintf('%+.1f', $growth[1]),
            $this->fmt($operatingMargin),
        );
    }

    private function fmt(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 1);
    }
}
