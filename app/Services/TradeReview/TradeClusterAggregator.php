<?php

namespace App\Services\TradeReview;

use App\Models\TradeExecution;
use App\Services\TradeReview\Support\SituationAggregation;
use App\Services\TradeReview\Support\SituationCell;
use App\Services\TradeReview\Support\TradeComparison;
use App\Services\TradeReview\Support\TradeSituation;
use Illuminate\Support\Collection;

/**
 * Aggregates the trade comparisons by pre-trade situation (UC-019 基本フロー
 * 1・3・4): one cell per axis band × market × comparison type × horizon,
 * counted by cluster. A cluster is the chain of the cell's trades in the same
 * stock and direction whose +26週 windows overlap; it is represented by the
 * median diff rate of its rated 'ok' trades. Horizons and comparison types
 * are never combined (UC-018). Routine trades are only counted as excluded.
 * Pure: reads nothing beyond the given models.
 */
class TradeClusterAggregator
{
    private const CLUSTER_WINDOW_DAYS = 182;

    private const AXES = [
        'high_ratio' => 'highRatioBand',
        'relative_strength' => 'relativeStrengthBand',
        'holding' => 'holdingBand',
    ];

    /**
     * @param  Collection<int, TradeExecution>  $trades
     * @param  array<int, TradeSituation>  $situations  keyed by trade id
     * @param  list<TradeComparison>  $comparisons
     */
    public function aggregate(Collection $trades, array $situations, array $comparisons): SituationAggregation
    {
        $excludedRoutine = $trades->filter(fn (TradeExecution $t) => $t->is_routine)->count();

        $eligible = $trades
            ->filter(fn (TradeExecution $t) => ! $t->is_routine && isset($situations[$t->id]))
            ->keyBy('id');

        /** @var array<string, array{key: array{string, string, string, string, int}, entries: list<array{trade: TradeExecution, comparison: TradeComparison, situation: TradeSituation}>}> $groups */
        $groups = [];

        foreach ($comparisons as $comparison) {
            $trade = $eligible->get($comparison->tradeId);

            if ($trade === null) {
                continue;
            }

            $situation = $situations[$trade->id];

            foreach (self::AXES as $axis => $property) {
                $key = [$axis, $situation->{$property}, $trade->market, $comparison->type, $comparison->horizon];
                $groups[implode('|', $key)]['key'] = $key;
                $groups[implode('|', $key)]['entries'][] = compact('trade', 'comparison', 'situation');
            }
        }

        $cells = array_map(fn (array $group) => $this->cell($group['key'], $group['entries']), array_values($groups));

        return new SituationAggregation($excludedRoutine, $cells);
    }

    /**
     * @param  array{string, string, string, string, int}  $key
     * @param  list<array{trade: TradeExecution, comparison: TradeComparison, situation: TradeSituation}>  $entries
     */
    private function cell(array $key, array $entries): SituationCell
    {
        $counts = ['ok' => 0, 'pending' => 0, 'unavailable' => 0];
        $representatives = [];

        foreach ($this->clusters($entries) as $cluster) {
            $rates = [];
            $hasPending = false;

            foreach ($cluster as $entry) {
                $comparison = $entry['comparison'];

                if ($comparison->status === 'ok' && $comparison->diffRate !== null) {
                    $rates[] = $comparison->diffRate;
                } elseif ($comparison->status === 'pending') {
                    $hasPending = true;
                }
            }

            if ($rates !== []) {
                $counts['ok']++;
                $representatives[] = $this->percentile($rates, 0.5);
            } elseif ($hasPending) {
                $counts['pending']++;
            } else {
                $counts['unavailable']++;
            }
        }

        $hasOk = $representatives !== [];

        $newestFirst = $entries;
        usort($newestFirst, fn (array $x, array $y) => [$y['trade']->trade_date->toDateString(), $y['trade']->id]
            <=> [$x['trade']->trade_date->toDateString(), $x['trade']->id]);

        [$axis, $band, $market, $type, $horizon] = $key;

        return new SituationCell(
            axis: $axis,
            band: $band,
            market: $market,
            type: $type,
            horizon: $horizon,
            clusters: array_sum($counts),
            okClusters: $counts['ok'],
            pendingClusters: $counts['pending'],
            unavailableClusters: $counts['unavailable'],
            median: $hasOk ? $this->percentile($representatives, 0.5) : null,
            p25: $hasOk ? $this->percentile($representatives, 0.25) : null,
            p75: $hasOk ? $this->percentile($representatives, 0.75) : null,
            betterClusters: count(array_filter($representatives, fn (float $v) => $v > 0)),
            recordedTrades: count(array_filter($entries, fn (array $e) => $e['situation']->source === 'recorded')),
            tradeIds: array_map(fn (array $e) => $e['trade']->id, $newestFirst),
        );
    }

    /**
     * Chains the cell's trades per stock and direction: a trade joins the
     * current cluster while its date ≤ the latest (date + 182 days) in it.
     *
     * @param  list<array{trade: TradeExecution, comparison: TradeComparison, situation: TradeSituation}>  $entries
     * @return list<list<array{trade: TradeExecution, comparison: TradeComparison, situation: TradeSituation}>>
     */
    private function clusters(array $entries): array
    {
        $byStock = [];

        foreach ($entries as $entry) {
            $byStock[$entry['trade']->holding_id.'|'.$entry['trade']->kind][] = $entry;
        }

        $clusters = [];

        foreach ($byStock as $stockEntries) {
            usort($stockEntries, fn (array $x, array $y) => [$x['trade']->trade_date->toDateString(), $x['trade']->id]
                <=> [$y['trade']->trade_date->toDateString(), $y['trade']->id]);

            $current = [];
            $windowEnd = null;

            foreach ($stockEntries as $entry) {
                $date = $entry['trade']->trade_date->toDateString();

                if ($windowEnd !== null && $date > $windowEnd) {
                    $clusters[] = $current;
                    $current = [];
                    $windowEnd = null;
                }

                $current[] = $entry;
                $end = $entry['trade']->trade_date->copy()->addDays(self::CLUSTER_WINDOW_DAYS)->toDateString();
                $windowEnd = $windowEnd === null ? $end : max($windowEnd, $end);
            }

            if ($current !== []) {
                $clusters[] = $current;
            }
        }

        return $clusters;
    }

    /**
     * Linear interpolation between closest ranks (PERCENTILE.INC).
     *
     * @param  non-empty-list<float>  $values
     */
    private function percentile(array $values, float $p): float
    {
        sort($values);
        $h = (count($values) - 1) * $p;
        $lower = (int) floor($h);
        $upper = min($lower + 1, count($values) - 1);

        return $values[$lower] + ($h - $lower) * ($values[$upper] - $values[$lower]);
    }
}
