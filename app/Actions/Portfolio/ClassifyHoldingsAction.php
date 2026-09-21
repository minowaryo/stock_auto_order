<?php

namespace App\Actions\Portfolio;

use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Sector\SectorAllocationCalculator;
use Illuminate\Support\Collection;

/**
 * UC-013 (ポートフォリオ分類ダッシュボード, ADR-0014): re-projects the existing
 * extraction Actions (ShowLossReviewListAction / ShowSignalListAction /
 * ShowBuySignalListAction / ShowWatchlistAction / SectorAllocationCalculator)
 * into a single "each holding belongs to exactly one bucket" view. Pure
 * calculation / read-only — no new thresholds, no DB writes (ADR-0014 D2/D8).
 *
 * Priority (排他解決, D3): core_accumulation is resolved first (and, once
 * resolved, no further bucket is checked for that holding) → loss_review >
 * take_profit > add_on > hold.
 */
class ClassifyHoldingsAction
{
    /**
     * @var array<string, string>
     */
    private const BUCKET_GROUPS = [
        'core_accumulation' => 'hold',
        'loss_review' => 'reduce',
        'take_profit' => 'reduce',
        'add_on' => 'increase',
        'hold' => 'hold',
        'new_entry' => 'increase',
    ];

    /**
     * hold_watch 判定 (c) の叩き台バッファ境界（ADR-0014 D5、data-model.md
     * 「未確定」）: 整理検討ライン(-20%)の手前、レンジ上限の-15.0%以下。
     */
    private const HOLD_WATCH_GAIN_RATE_BUFFER = -15.0;

    public function __construct(
        private readonly ShowSignalListAction $showSignalListAction,
        private readonly ShowBuySignalListAction $showBuySignalListAction,
        private readonly ShowLossReviewListAction $showLossReviewListAction,
        private readonly ShowWatchlistAction $showWatchlistAction,
        private readonly SectorAllocationCalculator $sectorAllocationCalculator,
        private readonly FundamentalHealthEvaluator $fundamentalHealthEvaluator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $latestSnapshot = Snapshot::query()
            ->orderByDesc('snapshotted_at')
            ->orderByDesc('id')
            ->first();

        $watchlistRows = $this->showWatchlistAction->execute();

        if (! $latestSnapshot) {
            return $this->emptyResult(null, $watchlistRows);
        }

        $holdingSnapshots = HoldingSnapshot::query()
            ->where('snapshot_id', $latestSnapshot->id)
            ->with(['holding.sectorClassification', 'holding.fundamentalIndicator', 'holding.technicalIndicator', 'accounts'])
            ->get();

        if ($holdingSnapshots->isEmpty()) {
            return $this->emptyResult($latestSnapshot->snapshotted_at, $watchlistRows);
        }

        $sectorRows = $this->sectorAllocationCalculator->calculate();
        $sectorStatusByName = collect($sectorRows)->keyBy('sector_name');

        $lossReviewRows = $this->showLossReviewListAction->execute();
        $takeProfitRows = $this->showSignalListAction->execute();
        $addOnRows = $this->showBuySignalListAction->execute();

        // symbol_code は (symbol_code, market) の複合ユニークであり単独では
        // 一意ではない（レビュー指摘）。sourceRows（各供給元Actionの出力）は
        // symbol_code しか持たないため、同じ symbol_code を持つ複数銘柄が
        // ある場合はそれら全てを対象候補とする（過剰包含はしても、いずれか
        // が黙って消える方向のデータ欠落は起こさない）。
        $holdingIdsBySymbol = $holdingSnapshots
            ->groupBy(fn (HoldingSnapshot $hs) => $hs->holding->symbol_code)
            ->map(fn (Collection $group) => $group->map(fn (HoldingSnapshot $hs) => $hs->holding->id)->all());

        $eligibleHoldingIds = function (array $sourceRows) use ($holdingIdsBySymbol): array {
            return collect($sourceRows)
                ->flatMap(fn (array $row) => $holdingIdsBySymbol->get($row['symbol_code'], []))
                ->all();
        };

        $lossReviewHoldingIds = $eligibleHoldingIds($lossReviewRows);
        $takeProfitHoldingIds = $eligibleHoldingIds($takeProfitRows);
        $addOnHoldingIds = $eligibleHoldingIds($addOnRows);

        // ADR-0014 D9-4: bucket_reason を SignalCriteriaEvaluator の達成度
        // データから機械生成するため、供給元Actionが既に算出済みの criteria
        // を holding id 単位で引けるようにする（新しい判定は追加しない）。
        $criteriaByHoldingIdFor = function (array $sourceRows) use ($holdingIdsBySymbol): array {
            $map = [];

            foreach ($sourceRows as $row) {
                foreach ($holdingIdsBySymbol->get($row['symbol_code'], []) as $id) {
                    $map[$id] = $row['criteria'] ?? null;
                }
            }

            return $map;
        };

        $lossReviewCriteriaById = $criteriaByHoldingIdFor($lossReviewRows);
        $takeProfitCriteriaById = $criteriaByHoldingIdFor($takeProfitRows);
        $addOnCriteriaById = $criteriaByHoldingIdFor($addOnRows);

        /** @var array<int, array{bucket: string, also_matched: array<int, string>}> $classified */
        $classified = [];

        foreach ($holdingSnapshots as $holdingSnapshot) {
            $holdingId = $holdingSnapshot->holding->id;

            if ($this->isCoreAccumulation($holdingSnapshot)) {
                $classified[$holdingId] = ['bucket' => 'core_accumulation', 'also_matched' => []];

                continue;
            }

            $eligible = [];

            if (in_array($holdingId, $lossReviewHoldingIds, true)) {
                $eligible[] = 'loss_review';
            }

            if (in_array($holdingId, $takeProfitHoldingIds, true)) {
                $eligible[] = 'take_profit';
            }

            if (in_array($holdingId, $addOnHoldingIds, true)) {
                $eligible[] = 'add_on';
            }

            $classified[$holdingId] = [
                'bucket' => $eligible[0] ?? 'hold',
                'also_matched' => array_slice($eligible, 1),
            ];
        }

        /** @var array<int, array<string, mixed>> $rowsById */
        $rowsById = [];

        foreach ($holdingSnapshots as $holdingSnapshot) {
            $holdingId = $holdingSnapshot->holding->id;
            $info = $classified[$holdingId];

            $criteria = match ($info['bucket']) {
                'loss_review' => $lossReviewCriteriaById[$holdingId] ?? null,
                'take_profit' => $takeProfitCriteriaById[$holdingId] ?? null,
                'add_on' => $addOnCriteriaById[$holdingId] ?? null,
                default => null,
            };

            $rowsById[$holdingId] = $this->buildRow($holdingSnapshot, $info['bucket'], $info['also_matched'], $sectorStatusByName, $criteria);
        }

        $coreAccumulationHoldings = collect($rowsById)
            ->filter(fn (array $row, int $id) => $classified[$id]['bucket'] === 'core_accumulation')
            ->sortByDesc('market_value')
            ->values()
            ->all();

        $holdHoldings = collect($rowsById)
            ->filter(fn (array $row, int $id) => $classified[$id]['bucket'] === 'hold')
            ->sort(fn (array $a, array $b) => (($b['hold_watch'] ? 1 : 0) <=> ($a['hold_watch'] ? 1 : 0))
                ?: ((float) $a['unrealized_gain_rate'] <=> (float) $b['unrealized_gain_rate']))
            ->values()
            ->all();

        $lossReviewHoldings = $this->orderedBucketHoldings($lossReviewRows, $rowsById, $classified, $holdingIdsBySymbol, 'loss_review');
        $takeProfitHoldings = $this->orderedBucketHoldings($takeProfitRows, $rowsById, $classified, $holdingIdsBySymbol, 'take_profit');
        $addOnHoldings = $this->orderedBucketHoldings($addOnRows, $rowsById, $classified, $holdingIdsBySymbol, 'add_on');

        $newEntryHoldings = collect($watchlistRows)
            ->map(fn (array $row) => $this->newEntryRow($row))
            ->all();

        $buckets = [
            ['bucket' => 'core_accumulation', 'group' => self::BUCKET_GROUPS['core_accumulation'], 'holdings' => $coreAccumulationHoldings],
            ['bucket' => 'loss_review', 'group' => self::BUCKET_GROUPS['loss_review'], 'holdings' => $lossReviewHoldings],
            ['bucket' => 'take_profit', 'group' => self::BUCKET_GROUPS['take_profit'], 'holdings' => $takeProfitHoldings],
            ['bucket' => 'add_on', 'group' => self::BUCKET_GROUPS['add_on'], 'holdings' => $addOnHoldings],
            ['bucket' => 'hold', 'group' => self::BUCKET_GROUPS['hold'], 'holdings' => $holdHoldings],
            ['bucket' => 'new_entry', 'group' => self::BUCKET_GROUPS['new_entry'], 'holdings' => $newEntryHoldings],
        ];

        $total = array_sum(array_column($rowsById, 'market_value'));
        $rate = fn (float $value): float => $total > 0.0 ? $value / $total * 100 : 0.0;

        $reduceTotal = $this->sumMarketValue($lossReviewHoldings) + $this->sumMarketValue($takeProfitHoldings);
        $holdGroupTotal = $this->sumMarketValue($coreAccumulationHoldings) + $this->sumMarketValue($holdHoldings);
        $increaseTotal = $this->sumMarketValue($addOnHoldings);

        $groupSummary = [
            [
                'group' => 'reduce',
                'market_value_total' => $reduceTotal,
                'allocation_rate' => $rate($reduceTotal),
                'holding_count' => count($lossReviewHoldings) + count($takeProfitHoldings),
            ],
            [
                'group' => 'hold',
                'market_value_total' => $holdGroupTotal,
                'allocation_rate' => $rate($holdGroupTotal),
                'holding_count' => count($coreAccumulationHoldings) + count($holdHoldings),
            ],
            [
                'group' => 'increase',
                'market_value_total' => $increaseTotal,
                'allocation_rate' => $rate($increaseTotal),
                'holding_count' => count($addOnHoldings),
            ],
        ];

        $coreTotal = $this->sumMarketValue($coreAccumulationHoldings);
        $holdOnlyTotal = $this->sumMarketValue($holdHoldings);

        $holdBreakdown = [
            'core_accumulation' => [
                'market_value_total' => $coreTotal,
                'allocation_rate' => $rate($coreTotal),
                'holding_count' => count($coreAccumulationHoldings),
            ],
            'hold' => [
                'market_value_total' => $holdOnlyTotal,
                'allocation_rate' => $rate($holdOnlyTotal),
                'holding_count' => count($holdHoldings),
            ],
        ];

        $sectorOverweightSummary = collect($sectorRows)
            ->sortByDesc('allocation_rate')
            ->map(fn (array $row) => [
                'sector_name' => $row['sector_name'],
                'allocation_rate' => $row['allocation_rate'],
                'allocation_status' => $row['allocation_status'],
            ])
            ->values()
            ->all();

        return [
            'classified_at' => $latestSnapshot->snapshotted_at,
            'group_summary' => $groupSummary,
            'hold_breakdown' => $holdBreakdown,
            'buckets' => $buckets,
            'sector_overweight_summary' => $sectorOverweightSummary,
            'new_entry_reference' => $watchlistRows,
        ];
    }

    /**
     * Preserves the order returned by the supplied source Action (D10:
     * add_on / loss_review / take_profit reuse the source's own ordering
     * without re-sorting), filtered to the rows that were finally assigned
     * to $bucket (排他解決の結果、他バケツを優先採用した銘柄は除外する).
     *
     * $sourceRows only carries symbol_code (not market), so it is resolved
     * back to holding id via $holdingIdsBySymbol built from this action's
     * own $holdingSnapshots. The sub-actions each independently re-resolve
     * "latest snapshot" internally, so if a new import completed mid-request
     * their rows could reference a symbol_code absent from our snapshot
     * (or already excluded by a higher-priority bucket); such rows are
     * skipped here rather than producing a null entry that would crash the
     * view. This does not eliminate that race (fixing it would require
     * threading an explicit snapshot through the sibling Actions, out of
     * scope for this re-projection cycle), only its crash consequence.
     *
     * @param  array<int, array<string, mixed>>  $sourceRows
     * @param  array<int, array<string, mixed>>  $rowsById
     * @param  array<int, array{bucket: string, also_matched: array<int, string>}>  $classified
     * @param  Collection<string, array<int, int>>  $holdingIdsBySymbol
     * @return array<int, array<string, mixed>>
     */
    private function orderedBucketHoldings(array $sourceRows, array $rowsById, array $classified, Collection $holdingIdsBySymbol, string $bucket): array
    {
        return collect($sourceRows)
            ->flatMap(fn (array $row) => $holdingIdsBySymbol->get($row['symbol_code'], []))
            ->unique()
            ->filter(fn (int $id) => ($classified[$id]['bucket'] ?? null) === $bucket && isset($rowsById[$id]))
            ->map(fn (int $id) => $rowsById[$id])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumMarketValue(array $rows): float
    {
        return (float) array_sum(array_column($rows, 'market_value'));
    }

    /**
     * ADR-0014 D2: instrument_type が ETF / 投資信託、または保有の口座区分が
     * すべて NISAつみたて投資枠のみの場合に core_accumulation とする。
     */
    private function isCoreAccumulation(HoldingSnapshot $holdingSnapshot): bool
    {
        $holding = $holdingSnapshot->holding;

        if (in_array($holding->instrument_type, ['etf', 'mutual_fund'], true)) {
            return true;
        }

        $accounts = $holdingSnapshot->accounts;

        return $accounts->isNotEmpty() && $accounts->every(fn ($account) => $account->account_type === 'nisa_tsumitate');
    }

    /**
     * @param  array<int, string>  $alsoMatched
     * @param  Collection<string, array<string, mixed>>  $sectorStatusByName
     * @param  array<string, mixed>|null  $criteria  loss_review/take_profit/add_on のみ、供給元Actionが算出済みのSignalCriteriaEvaluator出力
     * @return array<string, mixed>
     */
    private function buildRow(HoldingSnapshot $holdingSnapshot, string $bucket, array $alsoMatched, Collection $sectorStatusByName, ?array $criteria = null): array
    {
        $holding = $holdingSnapshot->holding;
        $unrealizedGainRate = $holdingSnapshot->unrealized_gain_rate !== null ? (float) $holdingSnapshot->unrealized_gain_rate : null;

        $sectorName = $holding->sectorClassification?->name ?? '未分類';
        $overweightSector = ($sectorStatusByName->get($sectorName)['allocation_status'] ?? null) === '偏り警告';

        $holdWatch = false;
        $healthLine = null;

        if ($bucket === 'hold') {
            $fundamentalStatus = $this->fundamentalStatus($holding);
            $holdWatch = $this->isHoldWatch($holdingSnapshot, $unrealizedGainRate, $fundamentalStatus);
            $healthLine = $this->healthLine($holdingSnapshot, $unrealizedGainRate, $fundamentalStatus);
        }

        return [
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'market' => $holding->market,
            'instrument_type' => $holding->instrument_type,
            'market_value' => $this->marketValue($holdingSnapshot),
            'unrealized_gain_rate' => $unrealizedGainRate,
            'bucket_reason' => $this->bucketReason($bucket, $criteria),
            'also_matched' => $alsoMatched,
            'overweight_sector' => $overweightSector,
            'hold_watch' => $holdWatch,
            'health_line' => $healthLine,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newEntryRow(array $watchlistRow): array
    {
        return [
            'symbol_code' => $watchlistRow['symbol_code'],
            'symbol_name' => $watchlistRow['symbol_name'],
            'market' => $watchlistRow['market'] ?? null,
            'instrument_type' => 'stock',
            'market_value' => null,
            'unrealized_gain_rate' => null,
            'bucket_reason' => '未保有のウォッチリスト銘柄（新規購入検討）',
            'also_matched' => [],
            'overweight_sector' => false,
            'hold_watch' => false,
            'health_line' => null,
        ];
    }

    /**
     * ADR-0014 D2/D6 と同じ算出式（保有数量 × 現在値。投資信託は基準価額の
     * ÷10000補正込み。US株の円換算は取込時済みの current_price を用いる）。
     * SectorAllocationCalculator::evaluationTotal() / PortfolioEvaluationCalculator
     * が用いる既存の算出式を1行単位に適用したもので、新しい算出式ではない。
     */
    private function marketValue(HoldingSnapshot $holdingSnapshot): float
    {
        $value = (float) $holdingSnapshot->quantity * (float) $holdingSnapshot->current_price;

        if ($holdingSnapshot->holding->instrument_type === 'mutual_fund') {
            $value /= 10000;
        }

        return $value;
    }

    private function fundamentalStatus(Holding $holding): string
    {
        [$equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth] = $holding->fundamentalIndicator?->healthEvaluatorArgs()
            ?? [null, null, null, null, null, null, null];

        return $this->fundamentalHealthEvaluator->evaluate($equityRatio, $roe, $revenueGrowth, $operatingIncomeGrowth, $operatingMargin, $avgRevenueGrowth, $avgOperatingIncomeGrowth);
    }

    /**
     * hold_watch 判定 (ADR-0014 D5): 既存の評価値のみを組み合わせる。
     * (a) 財務健全性 failed (b) 相対力〔対市場〕が継続マイナス（直近値で近似）
     * (c) 含み益率が整理検討ラインの手前（叩き台-15.0%以下）まで悪化。
     */
    private function isHoldWatch(HoldingSnapshot $holdingSnapshot, ?float $unrealizedGainRate, string $fundamentalStatus): bool
    {
        $holding = $holdingSnapshot->holding;

        if ($fundamentalStatus === 'failed') {
            return true;
        }

        // ADR-0015 D4: 対セクター相対力を優先し、未算出（null）なら対市場に
        // フォールバックする（BuySignalDeterminationService::preconditionsSatisfied()
        // / SignalCriteriaEvaluator::preferredRelativeStrength() と同じ優先順位）。
        $relativeStrength = $holding->technicalIndicator?->relative_strength_vs_sector
            ?? $holding->technicalIndicator?->relative_strength_vs_market;

        if ($relativeStrength !== null && (float) $relativeStrength < 0.0) {
            return true;
        }

        if ($unrealizedGainRate !== null && $unrealizedGainRate <= self::HOLD_WATCH_GAIN_RATE_BUFFER) {
            return true;
        }

        return false;
    }

    /**
     * hold バケツ専用の簡易ヘルスライン（含み益率・RSI・財務合否・相対力符号）。
     */
    private function healthLine(HoldingSnapshot $holdingSnapshot, ?float $unrealizedGainRate, string $fundamentalStatus): string
    {
        $technicalIndicator = $holdingSnapshot->holding->technicalIndicator;

        $rsi = $technicalIndicator?->rsi !== null ? (float) $technicalIndicator->rsi : null;
        // ADR-0015 D4: isHoldWatch()と同じ対セクター優先・対市場フォールバック。
        $relativeStrength = $technicalIndicator?->relative_strength_vs_sector !== null
            ? (float) $technicalIndicator->relative_strength_vs_sector
            : ($technicalIndicator?->relative_strength_vs_market !== null ? (float) $technicalIndicator->relative_strength_vs_market : null);

        return sprintf(
            '含み益率%s・RSI%s・財務%s・相対力%s',
            $unrealizedGainRate === null ? '-' : sprintf('%+.1f%%', $unrealizedGainRate),
            $rsi === null ? '-' : number_format($rsi, 1),
            $fundamentalStatus,
            $relativeStrength === null ? '-' : ($relativeStrength >= 0.0 ? '+' : '-'),
        );
    }

    /**
     * ADR-0014 D9-4: 一言評価はシンプルかつ理由が明快なものに限定し、
     * SignalCriteriaEvaluatorが既に算出済みの「基準値・実測値・達成状態」
     * データから機械的に生成する（例:「技術3/7達成・RSI72.1が基準≥70を
     * 満たす」）。複数要素を独自の重みで合成する文章生成は行わない。
     * `core_accumulation`/`hold`は供給元Actionのcriteriaを持たないため、
     * 固定文言のまま（`hold`はhealth_lineが個別値を別途表示する）。
     *
     * @param  array<string, mixed>|null  $criteria
     */
    private function bucketReason(string $bucket, ?array $criteria): string
    {
        if ($criteria === null) {
            return match ($bucket) {
                'core_accumulation' => '積立・インデックスコア（ETF/投資信託、またはNISAつみたて投資枠のみ）',
                'hold' => 'いずれの条件にも該当しないキープ銘柄',
                default => '',
            };
        }

        $summary = $criteria['summary']['technical'] ?? null;
        $sample = collect($criteria['technical'] ?? [])->firstWhere('status', 'met');

        if ($summary === null || $sample === null) {
            return match ($bucket) {
                'loss_review' => '整理検討ラインを超える含み損',
                'take_profit' => '利確検討条件を満たすシグナルあり',
                'add_on' => '押し目買いシグナル発生中',
                default => '',
            };
        }

        return sprintf(
            '技術%d/%d達成・%s%sが基準%sを満たす',
            $summary['met'],
            $summary['total'],
            $sample['label'],
            $sample['value_label'],
            $sample['threshold_label'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(?\DateTimeInterface $classifiedAt, array $watchlistRows): array
    {
        $groupSummary = array_map(fn (string $group) => [
            'group' => $group,
            'market_value_total' => 0.0,
            'allocation_rate' => 0.0,
            'holding_count' => 0,
        ], ['reduce', 'hold', 'increase']);

        $holdBreakdown = [
            'core_accumulation' => ['market_value_total' => 0.0, 'allocation_rate' => 0.0, 'holding_count' => 0],
            'hold' => ['market_value_total' => 0.0, 'allocation_rate' => 0.0, 'holding_count' => 0],
        ];

        $buckets = array_map(fn (string $bucket) => [
            'bucket' => $bucket,
            'group' => self::BUCKET_GROUPS[$bucket],
            'holdings' => [],
        ], ['core_accumulation', 'loss_review', 'take_profit', 'add_on', 'hold']);

        $buckets[] = [
            'bucket' => 'new_entry',
            'group' => self::BUCKET_GROUPS['new_entry'],
            'holdings' => collect($watchlistRows)->map(fn (array $row) => $this->newEntryRow($row))->all(),
        ];

        return [
            'classified_at' => $classifiedAt,
            'group_summary' => $groupSummary,
            'hold_breakdown' => $holdBreakdown,
            'buckets' => $buckets,
            'sector_overweight_summary' => [],
            'new_entry_reference' => $watchlistRows,
        ];
    }
}
