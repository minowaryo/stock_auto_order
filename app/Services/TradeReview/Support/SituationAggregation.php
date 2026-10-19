<?php

namespace App\Services\TradeReview\Support;

/**
 * The situation view's aggregation (UC-019 基本フロー1・3): the number of
 * routine trades left out, and the cells that hold at least one trade.
 */
final class SituationAggregation
{
    /**
     * @param  list<SituationCell>  $cells
     */
    public function __construct(
        public readonly int $excludedRoutine,
        public readonly array $cells,
    ) {}
}
