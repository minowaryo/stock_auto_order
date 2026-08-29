<?php

namespace App\Actions\ImportSummaryReport;

use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\ImportSummaryReportItem;
use App\Models\Snapshot;
use App\Models\WatchedTheme;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\TakeProfitThresholdEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UC-009 (取込後サマリーレポート): recomputes the 利確検討/リバランス/新規投資候補
 * priority ranking for a given import batch's snapshot and persists the
 * result (docs/architecture/data-model.md#import_summary_reports /
 * #import_summary_report_items).
 *
 * The composite score formula/weights themselves are intentionally
 * undisclosed to the user (ADR-0003), but `reason_summary`/
 * `portfolio_headline` must reference the concrete indicator values that
 * drove the ranking.
 */
class ShowImportSummaryReportAction
{
    /**
     * セクター配分の偏り警告閾値 (data-model.md draft: 70%以上=偏り警告).
     */
    private const SECTOR_ALLOCATION_WARNING_THRESHOLD = 70.0;

    /**
     * 財務健全性フィルタ (data-model.md draft: 自己資本比率40%以上・ROE10%以上).
     */
    private const NEW_CANDIDATE_MIN_EQUITY_RATIO = 40.0;

    private const NEW_CANDIDATE_MIN_ROE = 10.0;

    private const TOP_COUNT = 10;

    private const TOTAL_COUNT = 20;

    public function __construct(
        private readonly FundamentalHealthEvaluator $evaluator,
        private readonly TakeProfitThresholdEvaluator $takeProfitThresholdEvaluator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ImportBatch $importBatch): array
    {
        $snapshot = Snapshot::query()->where('import_batch_id', $importBatch->id)->first();

        $candidates = $snapshot
            ? $this->buildCandidates($snapshot)
            : collect();

        $sorted = $candidates->sortByDesc(fn (array $candidate) => $candidate['composite_score'])->values();

        $ranked = $sorted->take(self::TOTAL_COUNT)->values()->map(function (array $candidate, int $index) {
            $candidate['rank'] = $index + 1;
            $candidate['is_supplementary'] = $candidate['rank'] > self::TOP_COUNT;

            return $candidate;
        });

        $top = $ranked->filter(fn (array $item) => $item['rank'] <= self::TOP_COUNT)->values();
        $supplementary = $ranked->filter(fn (array $item) => $item['rank'] > self::TOP_COUNT)->values();

        $headline = $this->buildHeadline($ranked, $sorted->count());

        $this->persist($importBatch, $headline, $ranked);

        return [
            'portfolio_headline' => $headline,
            'generated_at' => now(),
            'top_recommendations' => $top->map(fn (array $item) => $this->toResponseItem($item, includeSupplementaryFlag: false))->all(),
            'supplementary_recommendations' => $supplementary->map(fn (array $item) => $this->toResponseItem($item, includeSupplementaryFlag: true))->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildCandidates(Snapshot $snapshot): Collection
    {
        $holdingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $snapshot->id)
            ->with(['holding.sectorClassification', 'holding.technicalIndicator', 'holding.fundamentalIndicator', 'signals'])
            ->get();

        // UC-009業務ルール: 投資信託は対象外とする（UC-002業務ルールに準拠し、
        // 個別株〔JP株・US株〕のみを対象とする）。
        $stockHoldingSnapshots = $holdingSnapshots->filter(
            fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->holding->instrument_type === 'stock'
                && in_array($holdingSnapshot->holding->market, ['jp', 'us'], true)
        );

        return collect()
            ->merge($this->buildTakeProfitCandidates($stockHoldingSnapshots))
            ->merge($this->buildRebalanceCandidates($stockHoldingSnapshots))
            ->merge($this->buildNewCandidateItems($snapshot, $holdingSnapshots));
    }

    /**
     * @param  Collection<int, HoldingSnapshot>  $stockHoldingSnapshots
     * @return array<int, array<string, mixed>>
     */
    private function buildTakeProfitCandidates(Collection $stockHoldingSnapshots): array
    {
        $items = [];

        foreach ($stockHoldingSnapshots as $holdingSnapshot) {
            $gainRate = (float) $holdingSnapshot->unrealized_gain_rate;

            $holding = $holdingSnapshot->holding;

            // ADR-0004: reflect saved signals (UC-004's 7 signal types) into
            // both the reason_summary wording and the composite score, on
            // top of the existing gain-rate/RSI-only ranking. Eager-loaded
            // on $holdingSnapshot (buildCandidates()'s with()) rather than
            // queried per-iteration, to avoid an N+1 now that the signal
            // count is needed unconditionally (CHG-0006) instead of only
            // for holdings already past the old fixed +20% gate.
            $signals = $holdingSnapshot->signals;

            // CHG-0006: the +20%超 threshold dynamically switches to +150%超
            // ("高水準モード") when the holding has zero signals and passes
            // the financial health filter (TakeProfitThresholdEvaluator).
            [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth] = $holding->fundamentalIndicator?->healthEvaluatorArgs()
                ?? [null, null, null, null];

            $threshold = $this->takeProfitThresholdEvaluator->evaluate(
                $signals->count(),
                $equityRatio,
                $roe,
                $revenueGrowth,
                $operatingIncomeGrowth,
            );

            if ($gainRate <= $threshold['target_gain_rate_threshold']) {
                continue;
            }

            $rsi = $holding->technicalIndicator?->rsi !== null ? (float) $holding->technicalIndicator->rsi : null;

            $reasonSummary = $rsi !== null
                ? sprintf('含み益+%s%%・RSI%sが中心的根拠', $this->fmt($gainRate), $this->fmt($rsi))
                : sprintf('含み益+%s%%が中心的根拠', $this->fmt($gainRate));

            if ($signals->isNotEmpty()) {
                $reasonSummary .= '、'.$signals->pluck('reason_summary')->implode('、');
            }

            $items[] = [
                'recommendation_type' => '利確検討',
                'target' => "{$holding->symbol_code} {$holding->symbol_name}",
                'symbol_code' => $holding->symbol_code,
                'action_suggestion' => '含み益の一部について利益確定（分割売却）を検討してください',
                'reason_summary' => $reasonSummary,
                'link_to' => 'UC-003',
                'composite_score' => $gainRate + ($rsi ?? 0.0) + $signals->count() * 15,
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, HoldingSnapshot>  $stockHoldingSnapshots
     * @return array<int, array<string, mixed>>
     */
    private function buildRebalanceCandidates(Collection $stockHoldingSnapshots): array
    {
        $bySector = $stockHoldingSnapshots
            ->filter(fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->holding->sectorClassification !== null)
            ->groupBy(fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->holding->sectorClassification->name);

        $sectorValues = $bySector->map(
            fn (Collection $group) => $group->sum(fn (HoldingSnapshot $holdingSnapshot) => (float) $holdingSnapshot->quantity * (float) $holdingSnapshot->current_price)
        );

        $totalValue = $sectorValues->sum();

        if ($totalValue <= 0.0) {
            return [];
        }

        $items = [];

        foreach ($sectorValues as $sectorName => $value) {
            $percentage = $value / $totalValue * 100;

            if ($percentage < self::SECTOR_ALLOCATION_WARNING_THRESHOLD) {
                continue;
            }

            $items[] = [
                'recommendation_type' => 'リバランス',
                'target' => $sectorName,
                'action_suggestion' => 'セクター配分の偏りを是正するため、他セクターへの分散を検討してください',
                'reason_summary' => sprintf(
                    '配分%s%%が%sに集中しています（目標%s%%を超過）',
                    $this->fmt($percentage),
                    $sectorName,
                    $this->fmt(self::SECTOR_ALLOCATION_WARNING_THRESHOLD),
                ),
                'link_to' => 'UC-005',
                'composite_score' => $percentage,
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, HoldingSnapshot>  $allHoldingSnapshots
     * @return array<int, array<string, mixed>>
     */
    private function buildNewCandidateItems(Snapshot $snapshot, Collection $allHoldingSnapshots): array
    {
        $themeNames = WatchedTheme::query()->pluck('name');

        if ($themeNames->isEmpty()) {
            return [];
        }

        $heldHoldingIds = $allHoldingSnapshots->pluck('holding_id')->all();

        $candidateHoldings = Holding::query()
            ->whereNotIn('id', $heldHoldingIds)
            ->where('instrument_type', 'stock')
            ->whereNotNull('sector_classification_id')
            ->whereHas('sectorClassification', fn ($query) => $query->whereIn('name', $themeNames))
            ->whereHas('fundamentalIndicator', function ($query) {
                $query->where('equity_ratio', '>=', self::NEW_CANDIDATE_MIN_EQUITY_RATIO)
                    ->where('roe', '>=', self::NEW_CANDIDATE_MIN_ROE);
            })
            ->with(['fundamentalIndicator'])
            ->get();

        $items = [];

        foreach ($candidateHoldings as $holding) {
            $fundamentalIndicator = $holding->fundamentalIndicator;
            $equityRatio = (float) $fundamentalIndicator->equity_ratio;
            $roe = (float) $fundamentalIndicator->roe;
            $revenueGrowth = $fundamentalIndicator->revenue_growth !== null ? (float) $fundamentalIndicator->revenue_growth : null;
            $operatingIncomeGrowth = $fundamentalIndicator->operating_income_growth !== null ? (float) $fundamentalIndicator->operating_income_growth : null;

            // CHG-0005: 財務健全性フィルタに成長率条件（売上高または営業利益
            // 成長率のいずれかがプラス）を追加する。equity_ratio/roeのDB
            // クエリでの事前絞り込みに加え、FundamentalHealthEvaluatorを
            // Source of Truthとして'passed'（unavailable/failedはいずれも
            // 除外）の銘柄のみを新規投資候補として残す。
            if ($this->evaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth) !== 'passed') {
                continue;
            }

            $items[] = [
                'recommendation_type' => '新規投資候補',
                'target' => "{$holding->symbol_code} {$holding->symbol_name}",
                'symbol_code' => $holding->symbol_code,
                'action_suggestion' => '注目テーマ合致・財務健全性の高い新規投資候補として検討してください',
                'reason_summary' => sprintf('自己資本比率%s%%・ROE%sが中心的根拠', $this->fmt($equityRatio), $this->fmt($roe)),
                'link_to' => 'UC-006',
                'composite_score' => $equityRatio + $roe,
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rankedItems
     */
    private function buildHeadline(Collection $rankedItems, int $totalCount): string
    {
        if ($totalCount === 0) {
            return '現時点でおすすめできる項目はありません';
        }

        $topItem = $rankedItems->first();

        return sprintf(
            '%d件の候補を検出しました。最優先候補: %s（%s）',
            $totalCount,
            $topItem['target'],
            $topItem['reason_summary'],
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rankedItems
     */
    private function persist(ImportBatch $importBatch, string $headline, Collection $rankedItems): void
    {
        DB::transaction(function () use ($importBatch, $headline, $rankedItems) {
            $report = $importBatch->summaryReport()->updateOrCreate(
                ['import_batch_id' => $importBatch->id],
                ['portfolio_headline' => $headline, 'generated_at' => now()],
            );

            ImportSummaryReportItem::query()->where('import_summary_report_id', $report->id)->delete();

            foreach ($rankedItems as $item) {
                ImportSummaryReportItem::create([
                    'import_summary_report_id' => $report->id,
                    'rank' => $item['rank'],
                    'is_supplementary' => $item['is_supplementary'],
                    'recommendation_type' => $item['recommendation_type'],
                    'target_label' => $item['target'],
                    'action_suggestion' => $item['action_suggestion'],
                    'reason_summary' => $item['reason_summary'],
                    'link_to' => $item['link_to'],
                    'composite_score' => $item['composite_score'],
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function toResponseItem(array $item, bool $includeSupplementaryFlag): array
    {
        $response = [
            'rank' => $item['rank'],
            'recommendation_type' => $item['recommendation_type'],
            'target' => $item['target'],
            'action_suggestion' => $item['action_suggestion'],
            'reason_summary' => $item['reason_summary'],
            'link_to' => $item['link_to'],
        ];

        if (array_key_exists('symbol_code', $item)) {
            $response['symbol_code'] = $item['symbol_code'];
        }

        if ($includeSupplementaryFlag) {
            $response['is_supplementary'] = true;
        }

        return $response;
    }

    private function fmt(float $value): string
    {
        return (string) (int) round($value);
    }
}
