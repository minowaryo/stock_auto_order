<?php

namespace App\Actions\TradeReview;

use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\IndicatorObservation;
use App\Models\PriceTrackingTarget;
use App\Models\SignalOccurrence;
use App\Models\StockSplit;
use App\Models\TradeExecution;
use App\Models\WeeklyPrice;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\TradeReview\StockPerformanceCalculator;
use App\Services\TradeReview\Support\PeriodPerformance;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\Support\SituationCell;
use App\Services\TradeReview\Support\TradeComparison;
use App\Services\TradeReview\Support\TradeContext;
use App\Services\TradeReview\Support\TradeSituation;
use App\Services\TradeReview\SwitchAllocator;
use App\Services\TradeReview\TradeClusterAggregator;
use App\Services\TradeReview\TradeComparisonCalculator;
use App\Services\TradeReview\TradeContextFinder;
use App\Services\TradeReview\TradeSituationClassifier;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The data of the 売買の振り返り screen (UC-018): the stock-part return and
 * its judgement for the latest 52 weeks and each calendar year, and every
 * sell / buy with its comparisons and the situation recorded before it; and
 * the 状況別 aggregation (UC-019), built from the same weeks, comparisons and
 * contexts. The report holds plain arrays and scalars only (the database
 * cache store unserializes with allowed_classes = false).
 *
 * The whole calculation takes a few seconds, so the result is cached in ONE
 * cache entry together with a signature of every table it reads; any new
 * trade, price, split or record changes the signature and the entry is
 * rebuilt and overwritten (no stale entries pile up in the cache table).
 */
class ShowTradeReviewAction
{
    private const JUDGE_WEEKS = 52;

    private const CACHE_KEY = 'trade-review';

    private const MARKETS = ['all' => null, 'jp' => 'jp', 'us' => 'us'];

    private const SITUATION_MARKETS = ['jp' => '国内株', 'us' => '米国株'];

    /** The bands of each 状況別 axis, in display order, with their labels. */
    private const SITUATION_BANDS = [
        'high_ratio' => ['near_high' => '高値圏（52週高値の85%以上）', 'pullback' => '押し目圏（85%未満）', 'unknown' => '不明'],
        'relative_strength' => ['strong' => '強い（対市場−5pt以上）', 'weak' => '弱い（−5pt未満）', 'unknown' => '不明'],
        'holding' => ['none' => '未保有', 'lt1' => '保有1%未満', '1to5' => '保有1〜5%', 'gt5' => '保有5%超'],
    ];

    public function __construct(
        private readonly WeeklyPortfolioBuilder $builder,
        private readonly StockPerformanceCalculator $performance,
        private readonly TradeComparisonCalculator $comparisons,
        private readonly TradeContextFinder $contexts,
        private readonly WeekDateNormalizer $weeks,
        private readonly TradeSituationClassifier $classifier,
        private readonly TradeClusterAggregator $aggregator,
    ) {}

    /**
     * @return array{latest: array<string, array<string, mixed>>|null, years: array<int, array<string, array<string, mixed>>>, trades: list<array<string, mixed>>, situations: array<string, mixed>}
     */
    public function execute(): array
    {
        $signature = $this->signature();
        $code = $this->codeFingerprint();
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ($cached['signature'] ?? null) === $signature && ($cached['code'] ?? null) === $code) {
            return $cached['report'];
        }

        $report = $this->build();
        Cache::forever(self::CACHE_KEY, ['signature' => $signature, 'code' => $code, 'report' => $report]);

        return $report;
    }

    /**
     * @return array{latest: array<string, array<string, mixed>>|null, years: array<int, array<string, array<string, mixed>>>, trades: list<array<string, mixed>>, situations: array<string, mixed>}
     */
    private function build(): array
    {
        $weeks = $this->builder->build();

        if ($weeks === []) {
            return ['latest' => null, 'years' => [], 'trades' => [], 'situations' => $this->emptySituations()];
        }

        $markets = Holding::query()->pluck('market', 'id')->all();

        // Shared by 売買一覧 and 状況別: each is calculated once per rebuild.
        $comparisons = [...$this->comparisons->sells($weeks), ...$this->comparisons->switches($weeks), ...$this->comparisons->buys($weeks)];
        $contexts = $this->contexts->find($weeks);
        $trades = TradeExecution::query()
            ->with('holding:id,symbol_code,symbol_name')
            ->whereIn('kind', ['sell', 'buy', 'tsumitate'])
            ->orderByDesc('trade_date')
            ->orderByDesc('id')
            ->get();

        return [
            'latest' => $this->latest($weeks, $markets),
            'years' => $this->years($weeks, $markets),
            'trades' => $this->trades($trades, $comparisons, $contexts),
            'situations' => $this->situations($weeks, $trades, $comparisons, $contexts),
        ];
    }

    /**
     * @param  array<string, mixed>  $weeks
     * @param  array<int, string>  $markets
     * @return array<string, array<string, mixed>>
     */
    private function latest(array $weeks, array $markets): array
    {
        $end = array_key_last($weeks);

        return $this->byMarket($weeks, $markets, Carbon::parse($end)->subWeeks(self::JUDGE_WEEKS)->toDateString(), $end);
    }

    /**
     * @param  array<string, mixed>  $weeks
     * @param  array<int, string>  $markets
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function years(array $weeks, array $markets): array
    {
        $byYear = [];

        foreach (array_keys($weeks) as $week) {
            $byYear[(int) substr($week, 0, 4)][] = $week;
        }

        $result = [];

        foreach ($byYear as $year => $yearWeeks) {
            $result[$year] = $this->byMarket($weeks, $markets, Carbon::parse($yearWeeks[0])->subWeek()->toDateString(), end($yearWeeks));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $weeks
     * @param  array<int, string>  $markets
     * @return array<string, array<string, mixed>>
     */
    private function byMarket(array $weeks, array $markets, string $base, string $end): array
    {
        $rows = [];
        $starts = $this->marketStarts($weeks, $markets);

        foreach (self::MARKETS as $key => $market) {
            // A period starts no earlier than the week this market's holdings begin,
            // so a market that started within it is 「参考」, not judged (2026-10-18).
            $from = isset($starts[$key]) ? max($base, $starts[$key]) : $base;
            $rows[$key] = $this->periodRow($this->performance->calculate($weeks, $markets, $market, $from, $end, self::JUDGE_WEEKS));
        }

        return $rows;
    }

    /**
     * The first week in which each market (and all together) holds or trades anything.
     *
     * @param  array<string, PortfolioWeek>  $weeks
     * @param  array<int, string>  $markets
     * @return array<string, string>
     */
    private function marketStarts(array $weeks, array $markets): array
    {
        $starts = [];

        foreach ($weeks as $week => $portfolio) {
            foreach (array_keys($portfolio->quantities + $portfolio->holdingFlowsJpy) as $id) {
                $starts['all'] ??= $week;
                $market = $markets[$id] ?? null;

                if ($market !== null) {
                    $starts[$market] ??= $week;
                }
            }
        }

        return $starts;
    }

    /**
     * A fingerprint of the code that builds the report, so a deploy that
     * changes the calculation or the labels rebuilds the cached report even
     * when the data did not change (found 2026-10-18).
     */
    private function codeFingerprint(): string
    {
        $files = [...glob(app_path('Services/TradeReview/*.php')), ...glob(app_path('Services/TradeReview/Support/*.php')), ...glob(app_path('Actions/TradeReview/*.php'))];
        sort($files);

        return md5(implode('|', array_map(fn (string $file) => md5_file($file), $files)));
    }

    /**
     * @return array<string, mixed>
     */
    private function periodRow(PeriodPerformance $p): array
    {
        return [
            'base_week' => $p->baseWeek,
            'end_week' => $p->endWeek,
            'return_rate' => $p->returnRate,
            'buy_hold_rate' => $p->buyHoldRate,
            'diff_points' => $p->diffPoints,
            'verdict' => $p->verdict,
            'period_target_20' => $p->periodTarget20,
        ];
    }

    /**
     * Sells and buys (routine buys excluded), newest first.
     *
     * @param  Collection<int, TradeExecution>  $trades  newest first
     * @param  list<TradeComparison>  $list
     * @param  array<int, TradeContext>  $contexts
     * @return list<array<string, mixed>>
     */
    private function trades(Collection $trades, array $list, array $contexts): array
    {
        $comparisons = $this->comparisonsByTrade($list);

        return $trades
            ->filter(fn (TradeExecution $trade) => in_array($trade->kind, ['sell', 'buy'], true))
            ->map(fn (TradeExecution $trade) => [
                'id' => $trade->id,
                'date' => $trade->trade_date->toDateString(),
                'year' => (int) $trade->trade_date->format('Y'),
                'market' => $trade->market,
                'kind' => $trade->kind,
                'code' => $trade->holding->symbol_code,
                'name' => $trade->holding->symbol_name,
                'amount_jpy' => SwitchAllocator::amountJpy($trade),
                'comparisons' => $trade->kind === 'buy' && ! isset($comparisons[$trade->id]['buy'])
                    // No new money left: the whole buy is evaluated on the switch side.
                    ? [['label' => '売却代金を充当（乗換え側で評価）', 'cells' => []]]
                    : $this->comparisonRows($comparisons[$trade->id] ?? []),
                'context' => $this->contextRow($contexts[$trade->id] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<TradeComparison>  $list
     * @return array<int, array<string, array<int, TradeComparison>>>
     */
    private function comparisonsByTrade(array $list): array
    {
        $comparisons = [];

        foreach ($list as $c) {
            $comparisons[$c->tradeId][$c->type][$c->horizon] = $c;
        }

        return $comparisons;
    }

    /**
     * The 状況別 tab (UC-019): the counts behind it, the rows per axis / type /
     * horizon (jp before us, bands in display order) and, for every
     * classified trade, its diff per type and horizon for the drill-down.
     *
     * @param  array<string, PortfolioWeek>  $weeks
     * @param  Collection<int, TradeExecution>  $trades  incl. routine ones (counted as excluded)
     * @param  list<TradeComparison>  $comparisons
     * @param  array<int, TradeContext>  $contexts
     * @return array{summary: array{trades: int, excluded_routine: int, recorded: int, recomputed: int}, rows: array<string, array<string, array<int, list<array<string, mixed>>>>>, trades: array<int, array<string, mixed>>}
     */
    private function situations(array $weeks, Collection $trades, array $comparisons, array $contexts): array
    {
        $situations = $this->classifier->classify($weeks, $contexts);
        $aggregation = $this->aggregator->aggregate($trades, $situations, $comparisons);
        $sources = array_count_values(array_map(fn (TradeSituation $s) => $s->source, $situations));

        $cells = $aggregation->cells;
        usort($cells, fn (SituationCell $x, SituationCell $y) => $this->situationOrder($x) <=> $this->situationOrder($y));
        $rows = [];

        foreach ($cells as $cell) {
            $rows[$cell->axis][$cell->type][$cell->horizon][] = [
                'key' => $cell->market.'-'.$cell->band,
                'market' => self::SITUATION_MARKETS[$cell->market] ?? $cell->market,
                'band' => self::SITUATION_BANDS[$cell->axis][$cell->band] ?? '不明',
                'clusters' => $cell->clusters,
                'ok' => $cell->okClusters,
                'pending' => $cell->pendingClusters,
                'unavailable' => $cell->unavailableClusters,
                'median' => $cell->median === null ? '—' : $this->rate($cell->median),
                'range' => $cell->p25 === null || $cell->p75 === null ? '—' : $this->rate($cell->p25).'〜'.$this->rate($cell->p75),
                'better' => $cell->betterClusters,
                'recorded' => $cell->recordedTrades,
                'trade_ids' => $cell->tradeIds,
            ];
        }

        $byTrade = $this->comparisonsByTrade($comparisons);
        $listed = [];

        foreach ($trades as $trade) {
            if (! isset($situations[$trade->id])) {
                continue;
            }

            $listed[$trade->id] = [
                'date' => $trade->trade_date->toDateString(),
                'code' => $trade->holding->symbol_code,
                'name' => $trade->holding->symbol_name,
                'diffs' => array_map(
                    fn (array $byHorizon) => array_map(fn (TradeComparison $c) => $this->cell($c->status, $c->diffRate), $byHorizon),
                    $byTrade[$trade->id] ?? [],
                ),
            ];
        }

        return [
            'summary' => [
                'trades' => count($situations),
                'excluded_routine' => $aggregation->excludedRoutine,
                'recorded' => $sources['recorded'] ?? 0,
                'recomputed' => $sources['recomputed'] ?? 0,
            ],
            'rows' => $rows,
            'trades' => $listed,
        ];
    }

    /**
     * @return array{summary: array{trades: int, excluded_routine: int, recorded: int, recomputed: int}, rows: array{}, trades: array{}}
     */
    private function emptySituations(): array
    {
        return ['summary' => ['trades' => 0, 'excluded_routine' => 0, 'recorded' => 0, 'recomputed' => 0], 'rows' => [], 'trades' => []];
    }

    /**
     * @return array{int, int}
     */
    private function situationOrder(SituationCell $cell): array
    {
        $bands = array_keys(self::SITUATION_BANDS[$cell->axis] ?? []);
        $band = array_search($cell->band, $bands, true);

        return [
            (int) array_search($cell->market, array_keys(self::SITUATION_MARKETS), true),
            $band === false ? count($bands) : $band,
        ];
    }

    private function rate(float $rate): string
    {
        return sprintf('%+.1f%%', $rate * 100);
    }

    /**
     * @param  array<string, array<int, TradeComparison>>  $byType
     * @return list<array{label: string, cells: array<int, string>}>
     */
    private function comparisonRows(array $byType): array
    {
        $rows = [];
        $labels = ['sell' => '売却単独', 'switch' => '乗換え（参考）', 'buy' => '比例買増し'];

        foreach ($labels as $type => $label) {
            if (! isset($byType[$type])) {
                continue;
            }

            $rows[] = ['label' => $label, 'cells' => array_map(fn (TradeComparison $c) => $this->cell($c->status, $c->diffRate), $byType[$type])];

            if ($type === 'buy') {
                $rows[] = ['label' => '指数（参考）', 'cells' => array_map(fn (TradeComparison $c) => $this->cell(
                    $c->status === 'pending' ? 'pending' : ($c->diffIndexJpy === null ? 'unavailable' : 'ok'),
                    $c->diffIndexJpy === null || $c->baseJpy <= 0 ? null : $c->diffIndexJpy / $c->baseJpy,
                ), $byType[$type])];
            }
        }

        return $rows;
    }

    private function cell(string $status, ?float $rate): string
    {
        return match (true) {
            $status === 'pending' => '結果待ち',
            $status !== 'ok' || $rate === null => '算出不可',
            default => sprintf('%+.1f%%', $rate * 100),
        };
    }

    /**
     * @return array{known: bool, signals: list<string>, held_label: string|null}
     */
    private function contextRow(?TradeContext $context): array
    {
        return [
            'known' => $context?->known ?? false,
            'signals' => array_values(array_unique(array_column($context?->signals ?? [], 'signal_type'))),
            'held_label' => $context === null || ! $context->heldBefore ? null
                : ($context->weightBefore < 0.01 ? '保有1%未満' : sprintf('保有%d%%', round($context->weightBefore * 100))),
        ];
    }

    /**
     * A key that changes whenever any table the calculation reads changes.
     */
    private function signature(): string
    {
        return md5(implode('|', [
            $this->weeks->lastConfirmedWeek(),
            TradeExecution::query()->count(), TradeExecution::query()->max('id'), TradeExecution::query()->max('updated_at'),
            WeeklyPrice::query()->count(), WeeklyPrice::query()->max('updated_at'),
            IndexWeeklyPrice::query()->count(), IndexWeeklyPrice::query()->max('updated_at'),
            StockSplit::query()->count(), StockSplit::query()->max('created_at'),
            PriceTrackingTarget::query()->max('updated_at'),
            SignalOccurrence::query()->max('id'), IndicatorObservation::query()->max('id'),
        ]));
    }
}
