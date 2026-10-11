<?php

namespace App\Services\TradeReview;

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
