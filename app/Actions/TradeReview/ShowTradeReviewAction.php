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
use App\Services\TradeReview\Support\TradeComparison;
use App\Services\TradeReview\Support\TradeContext;
use App\Services\TradeReview\SwitchAllocator;
use App\Services\TradeReview\TradeComparisonCalculator;
use App\Services\TradeReview\TradeContextFinder;
use App\Services\TradeReview\WeeklyPortfolioBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The data of the 売買の振り返り screen (UC-018): the stock-part return and
 * its judgement for the latest 52 weeks and each calendar year, and every
 * sell / buy with its comparisons and the situation recorded before it.
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

    public function __construct(
        private readonly WeeklyPortfolioBuilder $builder,
        private readonly StockPerformanceCalculator $performance,
        private readonly TradeComparisonCalculator $comparisons,
        private readonly TradeContextFinder $contexts,
        private readonly WeekDateNormalizer $weeks,
    ) {}

    /**
     * @return array{latest: array<string, array<string, mixed>>|null, years: array<int, array<string, array<string, mixed>>>, trades: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $signature = $this->signature();
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
            return $cached['report'];
        }

        $report = $this->build();
        Cache::forever(self::CACHE_KEY, ['signature' => $signature, 'report' => $report]);

        return $report;
    }

    /**
     * @return array{latest: array<string, array<string, mixed>>|null, years: array<int, array<string, array<string, mixed>>>, trades: list<array<string, mixed>>}
     */
    private function build(): array
    {
        $weeks = $this->builder->build();

        if ($weeks === []) {
            return ['latest' => null, 'years' => [], 'trades' => []];
        }

        $markets = Holding::query()->pluck('market', 'id')->all();

        return [
            'latest' => $this->latest($weeks, $markets),
            'years' => $this->years($weeks, $markets),
            'trades' => $this->trades($weeks),
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
        $base = max(Carbon::parse($end)->subWeeks(self::JUDGE_WEEKS)->toDateString(), array_key_first($weeks));

        return $this->byMarket($weeks, $markets, $base, $end);
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
            $base = max(Carbon::parse($yearWeeks[0])->subWeek()->toDateString(), array_key_first($weeks));
            $result[$year] = $this->byMarket($weeks, $markets, $base, end($yearWeeks));
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

        foreach (self::MARKETS as $key => $market) {
            $rows[$key] = $this->periodRow($this->performance->calculate($weeks, $markets, $market, $base, $end, self::JUDGE_WEEKS));
        }

        return $rows;
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
     * @param  array<string, mixed>  $weeks
     * @return list<array<string, mixed>>
     */
    private function trades(array $weeks): array
    {
        $comparisons = [];

        foreach ([...$this->comparisons->sells($weeks), ...$this->comparisons->switches($weeks), ...$this->comparisons->buys($weeks)] as $c) {
            $comparisons[$c->tradeId][$c->type][$c->horizon] = $c;
        }

        $contexts = $this->contexts->find($weeks);

        return TradeExecution::query()
            ->with('holding:id,symbol_code,symbol_name')
            ->whereIn('kind', ['sell', 'buy'])
            ->orderByDesc('trade_date')
            ->orderByDesc('id')
            ->get()
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
            ->all();
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
