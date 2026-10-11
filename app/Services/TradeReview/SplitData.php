<?php

namespace App\Services\TradeReview;

use App\Models\PriceTrackingTarget;
use App\Models\StockSplit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stock splits of a set of holdings, for the trade review (UC-018): the
 * factor that expresses a trade's shares after every later split (Yahoo
 * closes are split-adjusted), and the holdings whose prices cannot be trusted
 * yet (a split recorded after the last 10-year fetch, or a dropped split).
 */
final class SplitData
{
    /**
     * @param  Collection<int, Collection<int, StockSplit>>  $splits  by holding_id
     * @param  array<int, string>  $unreliable  holding_id => split_pending / split_incomplete
     */
    private function __construct(
        public readonly Collection $splits,
        public readonly array $unreliable,
    ) {}

    /**
     * @param  list<int>  $holdingIds
     */
    public static function load(array $holdingIds): self
    {
        $splits = StockSplit::query()->whereIn('holding_id', $holdingIds)->get()->groupBy('holding_id');
        $unreliable = [];

        foreach (PriceTrackingTarget::query()->whereIn('holding_id', $holdingIds)->get() as $target) {
            $fetchedAt = $target->full_history_fetched_at ?? $target->created_at;
            $lastSplit = $splits->get($target->holding_id)?->max('created_at');

            if ($lastSplit !== null && $fetchedAt !== null && Carbon::parse($lastSplit)->gt($fetchedAt)) {
                $unreliable[$target->holding_id] = 'split_pending';
            } elseif ($target->splits_incomplete) {
                $unreliable[$target->holding_id] = 'split_incomplete';
            }
        }

        return new self($splits, $unreliable);
    }

    /**
     * Shares of a trade on $tradeDate expressed after every later split.
     */
    public function factor(int $holdingId, string $tradeDate): float
    {
        $factor = 1.0;

        foreach ($this->splits->get($holdingId) ?? [] as $split) {
            if ($split->effective_date->toDateString() > $tradeDate) {
                $factor *= $split->ratio_numerator / $split->ratio_denominator;
            }
        }

        return $factor;
    }
}
