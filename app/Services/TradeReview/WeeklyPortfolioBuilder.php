<?php

namespace App\Services\TradeReview;

use App\Models\IndexWeeklyPrice;
use App\Models\PriceTrackingTarget;
use App\Models\StockSplit;
use App\Models\TradeExecution;
use App\Models\WeeklyPrice;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\TradeReview\Support\PortfolioWeek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rebuilds the stock part of the portfolio week by week from the trade
 * history (UC-018, Gate 2 draft 決定1): split-adjusted shares, the value
 * V_t (shares × weekly close, US × the same week's USD/JPY) and the money
 * flow F_t (buys − sells; transfers at market value). Holdings that cannot
 * be valued in a week are left out of both V_t and F_t and estimated
 * separately, so the caller can judge how much is missing.
 */
class WeeklyPortfolioBuilder
{
    private const ADDS = ['buy', 'tsumitate', 'transfer_in'];

    private const SUBTRACTS = ['sell', 'transfer_out'];

    public function __construct(private readonly WeekDateNormalizer $weeks) {}

    /**
     * @param  'jp'|'us'|null  $market
     * @return array<string, PortfolioWeek> keyed by the Monday of the week
     */
    public function build(?string $market = null): array
    {
        $trades = TradeExecution::query()
            ->with('holding:id,market')
            ->when($market !== null, fn ($q) => $q->where('market', $market))
            ->whereIn('kind', [...self::ADDS, ...self::SUBTRACTS])
            ->orderBy('trade_date')
            ->orderBy('id')
            ->get();

        if ($trades->isEmpty()) {
            return [];
        }

        $first = $this->weeks->weekStart($trades->first()->trade_date->toDateString());
        $last = $this->weeks->lastConfirmedWeek();

        if ($first > $last) {
            return [];
        }

        $holdingIds = $trades->pluck('holding_id')->unique()->values()->all();
        $markets = $trades->pluck('holding.market', 'holding_id')->all();
        $splits = StockSplit::query()->whereIn('holding_id', $holdingIds)->get()->groupBy('holding_id');
        $alwaysExcluded = $this->alwaysExcluded($holdingIds, $splits);
        $closes = $this->closes($holdingIds, $last);
        $usdJpy = IndexWeeklyPrice::query()
            ->where('index_name', 'usdjpy')
            ->where('week_date', '<=', $last)
            ->orderBy('week_date')
            ->pluck('close', 'week_date')
            ->mapWithKeys(fn ($close, $week) => [Carbon::parse($week)->toDateString() => (float) $close])
            ->all();

        $splitDeliveries = $this->splitDeliveries($trades, $splits);
        $trades = $trades->reject(fn (TradeExecution $t) => isset($splitDeliveries[$t->id]))->values();

        $tradesByWeek = $trades->groupBy(fn (TradeExecution $t) => $this->weeks->weekStart($t->trade_date->toDateString()));

        $quantities = [];
        $lastTradePrice = [];
        $result = [];

        for ($week = $first; $week <= $last; $week = Carbon::parse($week)->addWeek()->toDateString()) {
            $fx = $usdJpy[$week] ?? null;
            $flows = [];

            foreach ($tradesByWeek->get($week, collect()) as $trade) {
                $id = $trade->holding_id;
                $shares = (float) $trade->quantity * $this->splitFactor($splits->get($id), $trade->trade_date->toDateString());
                $sign = in_array($trade->kind, self::ADDS, true) ? 1 : -1;
                $quantities[$id] = ($quantities[$id] ?? 0.0) + $sign * $shares;
                $flows[$id] = ($flows[$id] ?? 0.0) + $sign * $this->amount($trade, $shares, $closes[$id][$week] ?? null, $markets[$id] === 'us' ? $fx : 1.0);

                if ($trade->unit_price !== null) {
                    $lastTradePrice[$id] = (float) $trade->unit_price * ($markets[$id] === 'us' ? (float) ($trade->fx_rate ?? $fx ?? 0) : 1.0);
                }
            }

            $value = 0.0;
            $flow = 0.0;
            $excluded = [];
            $estimate = 0.0;
            $holdingValues = [];
            $holdingFlows = [];
            $estimates = [];
            $prices = [];

            foreach ($holdingIds as $id) {
                $close = $closes[$id][$week] ?? null;
                $rate = $markets[$id] === 'us' ? $fx : 1.0;

                if ($close !== null && $rate !== null) {
                    $prices[$id] = $close * $rate;
                }
            }

            foreach (array_keys($quantities + $flows) as $id) {
                $shares = $quantities[$id] ?? 0.0;

                if (abs($shares) < 1e-9 && ! isset($flows[$id])) {
                    continue;
                }

                $close = $closes[$id][$week] ?? null;
                $rate = $markets[$id] === 'us' ? $fx : 1.0;
                $reason = $alwaysExcluded[$id] ?? ($close === null ? 'no_price' : ($rate === null ? 'no_fx' : null));

                if ($reason !== null) {
                    $excluded[$id] = $reason;
                    $estimates[$id] = $shares * $this->estimatePrice($closes[$id] ?? [], $week, $markets[$id] === 'us' ? $this->lastKnown($usdJpy, $week) : 1.0, $lastTradePrice[$id] ?? null);
                    $estimate += $estimates[$id];

                    continue;
                }

                $holdingValues[$id] = $shares * $close * $rate;
                $holdingFlows[$id] = $flows[$id] ?? 0.0;
                $value += $holdingValues[$id];
                $flow += $holdingFlows[$id];
            }

            ksort($excluded);
            ksort($holdingValues);
            ksort($holdingFlows);
            ksort($estimates);

            $result[$week] = new PortfolioWeek(
                week: $week,
                valueJpy: $value,
                flowJpy: $flow,
                quantities: array_filter($quantities, fn (float $q) => $q > 1e-9),
                excluded: $excluded,
                excludedEstimateJpy: $estimate,
                holdingValuesJpy: $holdingValues,
                holdingFlowsJpy: array_filter($holdingFlows, fn (float $f) => $f !== 0.0),
                excludedEstimatesJpy: $estimates,
                pricesJpy: $prices,
            );
        }

        return $result;
    }

    /**
     * JP trade CSVs book the shares a split adds as a plain 入庫 a few days
     * before Yahoo's split date. Such a transfer_in is not money in: shares
     * come from the split, which splitFactor() already applies. It is
     * recognised when a split of the holding falls within 7 days after it and
     * its quantity equals the account's shares before it × (ratio − 1).
     *
     * @param  Collection<int, TradeExecution>  $trades  ascending
     * @param  Collection<int, Collection<int, StockSplit>>  $splits
     * @return array<int, true> trade id => true
     */
    private function splitDeliveries(Collection $trades, Collection $splits): array
    {
        $balances = [];
        $deliveries = [];

        foreach ($trades as $trade) {
            $key = $trade->holding_id.'|'.$trade->account_type;
            $before = $balances[$key] ?? 0.0;
            $quantity = (float) $trade->quantity;

            if ($trade->market === 'jp' && $trade->kind === 'transfer_in') {
                $date = $trade->trade_date->toDateString();
                $limit = $trade->trade_date->copy()->addDays(7)->toDateString();
                $split = $splits->get($trade->holding_id)?->first(fn (StockSplit $s) => $s->effective_date->toDateString() > $date
                    && $s->effective_date->toDateString() <= $limit);

                if ($split !== null && abs($before * ($split->ratio_numerator / $split->ratio_denominator - 1) - $quantity) < 1e-6) {
                    $deliveries[$trade->id] = true;
                }
            }

            $balances[$key] = $before + (in_array($trade->kind, self::ADDS, true) ? $quantity : -$quantity);
        }

        return $deliveries;
    }

    /**
     * Holdings that cannot be valued in any week: a split recorded after the
     * last 10-year fetch (older closes not yet adjusted) or a dropped split.
     *
     * @param  list<int>  $holdingIds
     * @param  Collection<int, Collection<int, StockSplit>>  $splits
     * @return array<int, string>
     */
    private function alwaysExcluded(array $holdingIds, Collection $splits): array
    {
        $excluded = [];

        foreach (PriceTrackingTarget::query()->whereIn('holding_id', $holdingIds)->get() as $target) {
            $fetchedAt = $target->full_history_fetched_at ?? $target->created_at;
            $lastSplit = $splits->get($target->holding_id)?->max('created_at');

            if ($lastSplit !== null && $fetchedAt !== null && Carbon::parse($lastSplit)->gt($fetchedAt)) {
                $excluded[$target->holding_id] = 'split_pending';
            } elseif ($target->splits_incomplete) {
                $excluded[$target->holding_id] = 'split_incomplete';
            }
        }

        return $excluded;
    }

    /**
     * @param  list<int>  $holdingIds
     * @return array<int, array<string, float>> holding_id => [week => close], ascending
     */
    private function closes(array $holdingIds, string $last): array
    {
        $closes = [];

        WeeklyPrice::query()
            ->whereIn('holding_id', $holdingIds)
            ->where('week_date', '<=', $last)
            ->orderBy('week_date')
            ->get(['holding_id', 'week_date', 'close'])
            ->each(function (WeeklyPrice $row) use (&$closes) {
                $closes[$row->holding_id][$row->week_date->toDateString()] = (float) $row->close;
            });

        return $closes;
    }

    /**
     * Shares of a trade expressed after every later split (closes are split-adjusted).
     *
     * @param  Collection<int, StockSplit>|null  $splits
     */
    private function splitFactor(?Collection $splits, string $tradeDate): float
    {
        $factor = 1.0;

        foreach ($splits ?? [] as $split) {
            if ($split->effective_date->toDateString() > $tradeDate) {
                $factor *= $split->ratio_numerator / $split->ratio_denominator;
            }
        }

        return $factor;
    }

    /**
     * The JPY money of a trade: the settlement, or the market value for a
     * transfer that has none.
     */
    private function amount(TradeExecution $trade, float $shares, ?float $close, ?float $rate): float
    {
        if ($trade->settlement_amount_jpy !== null) {
            return (float) $trade->settlement_amount_jpy;
        }

        if ($trade->settlement_amount_usd !== null && $trade->fx_rate !== null) {
            return (float) $trade->settlement_amount_usd * (float) $trade->fx_rate;
        }

        return $close !== null && $rate !== null ? $shares * $close * $rate : 0.0;
    }

    /**
     * @param  array<string, float>  $closes
     */
    private function estimatePrice(array $closes, string $week, ?float $rate, ?float $lastTradePriceJpy): float
    {
        $close = $this->lastKnown($closes, $week);

        if ($close !== null && $rate !== null) {
            return $close * $rate;
        }

        return $lastTradePriceJpy ?? 0.0;
    }

    /**
     * @param  array<string, float>  $series  ascending by week
     */
    private function lastKnown(array $series, string $week): ?float
    {
        $found = null;

        foreach ($series as $at => $value) {
            if ($at > $week) {
                break;
            }

            $found = $value;
        }

        return $found;
    }
}
