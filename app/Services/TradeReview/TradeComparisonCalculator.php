<?php

namespace App\Services\TradeReview;

use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\TradeExecution;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\Support\TradeComparison;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Each sell compared with holding on (UC-018 基本フロー4) and each estimated
 * switch compared with holding on (基本フロー5, reference only), 4 / 13 / 26
 * weeks after the sell week, on the prices of WeeklyPortfolioBuilder. The
 * horizons are separate views and are never added up.
 */
class TradeComparisonCalculator
{
    private const HORIZONS = [4, 13, 26];

    /** A composition holding without a price is dropped only while its weight stays at or under this. */
    private const MAX_DROPPED_WEIGHT = 0.05;

    private const INDEX_FOR_MARKET = ['jp' => 'nikkei225', 'us' => 'sp500'];

    public function __construct(
        private readonly SwitchAllocator $allocator,
        private readonly WeekDateNormalizer $weeks,
    ) {}

    /**
     * @param  array<string, PortfolioWeek>  $weeks
     * @return list<TradeComparison>
     */
    public function sells(array $weeks): array
    {
        $sells = $this->sellTrades();
        $splits = SplitData::load($sells->pluck('holding_id')->unique()->values()->all());
        $result = [];

        foreach ($sells as $sell) {
            foreach ($this->evaluations($sell, $weeks) as [$horizon, $week, $status]) {
                $hold = $this->holdValue($sell, $weeks[$week] ?? null, $splits);
                $reason = $status === 'ok' ? $this->reason([$sell->holding_id], $splits, $hold === null) : null;
                $proceeds = SwitchAllocator::amountJpy($sell);

                $result[] = $this->comparison($sell, 'sell', $horizon, $week, $status, $reason, $proceeds, $proceeds, $hold);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, PortfolioWeek>  $weeks
     * @return list<TradeComparison>
     */
    public function switches(array $weeks): array
    {
        $allocations = collect($this->allocator->allocate())->groupBy('sell_id');

        if ($allocations->isEmpty()) {
            return [];
        }

        $trades = TradeExecution::query()
            ->whereIn('id', $allocations->keys()->merge($allocations->flatten(1)->pluck('buy_id'))->unique())
            ->get()
            ->keyBy('id');
        $splits = SplitData::load($trades->pluck('holding_id')->unique()->values()->all());
        $result = [];

        foreach ($allocations as $sellId => $parts) {
            $sell = $trades[$sellId];
            $proceeds = SwitchAllocator::amountJpy($sell);
            $holdings = [$sell->holding_id, ...$parts->map(fn (array $p) => $trades[$p['buy_id']]->holding_id)->all()];

            foreach ($this->evaluations($sell, $weeks) as [$horizon, $week, $status]) {
                $hold = $this->holdValue($sell, $weeks[$week] ?? null, $splits);
                $actual = $this->switchedValue($parts, $trades, $proceeds, $weeks[$week] ?? null, $splits);
                $reason = $status === 'ok' ? $this->reason($holdings, $splits, $hold === null || $actual === null) : null;

                $result[] = $this->comparison($sell, 'switch', $horizon, $week, $status, $reason, $proceeds, $actual, $hold);
            }
        }

        return $result;
    }

    /**
     * Each buy's new money (what the estimated switches did not cover)
     * against buying more of the same market's holdings in proportion to
     * their value at the end of the week before (the bought holding
     * included), and against the market index (reference). Routine buys are
     * never compared.
     *
     * @param  array<string, PortfolioWeek>  $weeks
     * @return list<TradeComparison>
     */
    public function buys(array $weeks): array
    {
        $buys = TradeExecution::query()->where('kind', 'buy')->orderBy('trade_date')->orderBy('id')->get();
        $allocated = [];

        foreach ($this->allocator->allocate() as $part) {
            $allocated[$part['buy_id']] = ($allocated[$part['buy_id']] ?? 0.0) + $part['amount_jpy'];
        }

        $splits = SplitData::load($buys->pluck('holding_id')->unique()->values()->all());
        $markets = Holding::query()->pluck('market', 'id')->all();
        $indices = $this->indexSeries();
        $result = [];

        foreach ($buys as $buy) {
            $cost = SwitchAllocator::amountJpy($buy);
            $newMoney = $cost - ($allocated[$buy->id] ?? 0.0);

            if ($newMoney <= 1e-9) {
                continue;
            }

            $buyWeek = $this->weeks->weekStart($buy->trade_date->toDateString());
            $weekBefore = $weeks[Carbon::parse($buyWeek)->subWeek()->toDateString()] ?? null;
            $composition = $this->composition($weekBefore, $markets, $buy->market);
            $leftOut = $this->leftOut($weekBefore, $markets, $buy->market);
            $shares = (float) $buy->quantity * $splits->factor($buy->holding_id, $buy->trade_date->toDateString());

            foreach ($this->evaluations($buy, $weeks) as [$horizon, $week, $status]) {
                $result[] = $this->buyComparison($buy, $horizon, $week, $status, $newMoney, $cost, $shares, $composition, $leftOut, $weeks[$buyWeek] ?? null, $weeks[$week] ?? null, $indices, $splits);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, float>  $composition  holding_id => value at the end of the week before
     * @param  array<string, array<string, float>>  $indices
     */
    private function buyComparison(TradeExecution $buy, int $horizon, string $week, string $status, float $newMoney, float $cost, float $shares, array $composition, float $leftOut, ?PortfolioWeek $buyWeek, ?PortfolioWeek $evaluation, array $indices, SplitData $splits): TradeComparison
    {
        $make = fn (string $status, ?string $reason, ?float $actual = null, ?float $hold = null, ?float $index = null) => new TradeComparison(
            tradeId: $buy->id,
            type: 'buy',
            horizon: $horizon,
            evaluationWeek: $week,
            status: $status,
            reason: $reason,
            baseJpy: $newMoney,
            actualJpy: $actual,
            holdJpy: $hold,
            diffJpy: $actual !== null && $hold !== null ? $actual - $hold : null,
            diffRate: $actual !== null && $hold !== null ? ($actual - $hold) / $newMoney : null,
            indexJpy: $index,
            diffIndexJpy: $actual !== null && $index !== null ? $actual - $index : null,
        );

        if ($status === 'pending') {
            return $make('pending', null);
        }

        if (isset($splits->unreliable[$buy->holding_id])) {
            return $make('unavailable', $splits->unreliable[$buy->holding_id]);
        }

        $price = $evaluation?->pricesJpy[$buy->holding_id] ?? null;

        if ($price === null) {
            return $make('unavailable', 'no_price');
        }

        $actual = $newMoney * $shares * $price / $cost;
        $index = $this->indexValue($indices, $buy->market, $buyWeek?->week, $week, $newMoney);

        if ($composition === []) {
            return $make('unavailable', 'no_holdings', $actual, null, $index);
        }

        $hold = $this->proportionalValue($composition, $leftOut, $newMoney, $buyWeek, $evaluation);

        return $hold === null
            ? $make('unavailable', 'no_price', $actual, null, $index)
            : $make('ok', null, $actual, $hold, $index);
    }

    /**
     * @param  array<int, string>  $markets
     * @return array<int, float>
     */
    private function composition(?PortfolioWeek $week, array $markets, string $market): array
    {
        return array_filter(
            $week?->holdingValuesJpy ?? [],
            fn (float $value, int $id) => $value > 0 && ($markets[$id] ?? null) === $market,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The estimated value of the market's holdings that were left out in the
     * week before (no close, split pending, ...): they belong to the
     * composition but cannot be bought at a known price.
     *
     * @param  array<int, string>  $markets
     */
    private function leftOut(?PortfolioWeek $week, array $markets, string $market): float
    {
        $sum = 0.0;

        foreach ($week?->excludedEstimatesJpy ?? [] as $id => $estimate) {
            $sum += ($markets[$id] ?? null) === $market ? $estimate : 0.0;
        }

        return $sum;
    }

    /**
     * The new money spread over the composition at the buy week's prices,
     * valued at the evaluation week. Holdings without either price, and those
     * already left out the week before, are dropped and the rest re-weighted,
     * unless more than 5% of the composition would be dropped.
     *
     * @param  array<int, float>  $composition
     */
    private function proportionalValue(array $composition, float $leftOut, float $newMoney, ?PortfolioWeek $buyWeek, ?PortfolioWeek $evaluation): ?float
    {
        $total = array_sum($composition) + $leftOut;
        $kept = array_filter(
            $composition,
            fn (int $id) => isset($buyWeek?->pricesJpy[$id], $evaluation?->pricesJpy[$id]),
            ARRAY_FILTER_USE_KEY,
        );
        $keptTotal = array_sum($kept);

        if ($keptTotal <= 0 || ($total - $keptTotal) / $total > self::MAX_DROPPED_WEIGHT + 1e-12) {
            return null;
        }

        $value = 0.0;

        foreach ($kept as $id => $weightValue) {
            $value += $newMoney * $weightValue * $evaluation->pricesJpy[$id] / ($keptTotal * $buyWeek->pricesJpy[$id]);
        }

        return $value;
    }

    /**
     * @param  array<string, array<string, float>>  $indices
     */
    private function indexValue(array $indices, string $market, ?string $buyWeek, string $week, float $newMoney): ?float
    {
        $name = self::INDEX_FOR_MARKET[$market] ?? null;
        $base = $buyWeek === null || $name === null ? null : ($indices[$name][$buyWeek] ?? null);
        $end = $name === null ? null : ($indices[$name][$week] ?? null);

        if ($market === 'us' && $base !== null && $end !== null) {
            $baseFx = $indices['usdjpy'][$buyWeek] ?? null;
            $endFx = $indices['usdjpy'][$week] ?? null;
            [$base, $end] = $baseFx === null || $endFx === null ? [null, null] : [$base * $baseFx, $end * $endFx];
        }

        return $base === null || $end === null || $base <= 0 ? null : $newMoney * $end / $base;
    }

    /**
     * @return array<string, array<string, float>> index_name => [week => close]
     */
    private function indexSeries(): array
    {
        $series = [];

        IndexWeeklyPrice::query()
            ->whereIn('index_name', ['nikkei225', 'sp500', 'usdjpy'])
            ->get(['index_name', 'week_date', 'close'])
            ->each(function (IndexWeeklyPrice $row) use (&$series) {
                $series[$row->index_name][$row->week_date->toDateString()] = (float) $row->close;
            });

        return $series;
    }

    /**
     * @return Collection<int, TradeExecution>
     */
    private function sellTrades(): Collection
    {
        return TradeExecution::query()->where('kind', 'sell')->orderBy('trade_date')->orderBy('id')->get();
    }

    /**
     * @param  array<string, PortfolioWeek>  $weeks
     * @return list<array{0: int, 1: string, 2: string}> [horizon, evaluation week, 'ok' | 'pending']
     */
    private function evaluations(TradeExecution $trade, array $weeks): array
    {
        $tradeWeek = $this->weeks->weekStart($trade->trade_date->toDateString());
        $last = array_key_last($weeks) ?? $tradeWeek;

        return array_map(function (int $horizon) use ($tradeWeek, $last) {
            $week = Carbon::parse($tradeWeek)->addWeeks($horizon)->toDateString();

            return [$horizon, $week, $week > $last ? 'pending' : 'ok'];
        }, self::HORIZONS);
    }

    private function holdValue(TradeExecution $sell, ?PortfolioWeek $week, SplitData $splits): ?float
    {
        $price = $week?->pricesJpy[$sell->holding_id] ?? null;

        return $price === null ? null
            : (float) $sell->quantity * $splits->factor($sell->holding_id, $sell->trade_date->toDateString()) * $price;
    }

    /**
     * Σ allocated / buy cost × buy shares × buy price, plus the proceeds left as cash.
     *
     * @param  Collection<int, array{sell_id: int, buy_id: int, amount_jpy: float}>  $parts
     * @param  Collection<int, TradeExecution>  $trades
     */
    private function switchedValue(Collection $parts, Collection $trades, float $proceeds, ?PortfolioWeek $week, SplitData $splits): ?float
    {
        $value = 0.0;
        $allocated = 0.0;

        foreach ($parts as $part) {
            $buy = $trades[$part['buy_id']];
            $price = $week?->pricesJpy[$buy->holding_id] ?? null;

            if ($price === null) {
                return null;
            }

            $shares = (float) $buy->quantity * $splits->factor($buy->holding_id, $buy->trade_date->toDateString());
            $value += $part['amount_jpy'] / SwitchAllocator::amountJpy($buy) * $shares * $price;
            $allocated += $part['amount_jpy'];
        }

        return $value + ($proceeds - $allocated);
    }

    /**
     * @param  list<int>  $holdingIds
     */
    private function reason(array $holdingIds, SplitData $splits, bool $missingPrice): ?string
    {
        foreach ($holdingIds as $id) {
            if (isset($splits->unreliable[$id])) {
                return $splits->unreliable[$id];
            }
        }

        return $missingPrice ? 'no_price' : null;
    }

    private function comparison(TradeExecution $trade, string $type, int $horizon, string $week, string $status, ?string $reason, float $base, ?float $actual, ?float $hold): TradeComparison
    {
        $ok = $status === 'ok' && $reason === null;

        return new TradeComparison(
            tradeId: $trade->id,
            type: $type,
            horizon: $horizon,
            evaluationWeek: $week,
            status: $ok ? 'ok' : ($status === 'pending' ? 'pending' : 'unavailable'),
            reason: $ok ? null : $reason,
            baseJpy: $base,
            actualJpy: $ok ? $actual : null,
            holdJpy: $ok ? $hold : null,
            diffJpy: $ok ? $actual - $hold : null,
            diffRate: $ok && $base > 0 ? ($actual - $hold) / $base : null,
        );
    }
}
