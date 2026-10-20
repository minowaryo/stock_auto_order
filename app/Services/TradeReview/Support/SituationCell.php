<?php

namespace App\Services\TradeReview\Support;

/**
 * The trades of one situation band, market, comparison type and horizon,
 * counted by cluster (UC-019 基本フロー3): counts and the distribution of the
 * clusters' representative diff rates only — no verdict or rule.
 */
final class SituationCell
{
    /**
     * @param  'high_ratio'|'relative_strength'|'holding'  $axis
     * @param  'jp'|'us'  $market
     * @param  'sell'|'switch'|'buy'  $type
     * @param  4|13|26  $horizon
     * @param  list<int>  $tradeIds  newest trade_date first, same date → id descending
     */
    public function __construct(
        public readonly string $axis,
        public readonly string $band,
        public readonly string $market,
        public readonly string $type,
        public readonly int $horizon,
        public readonly int $clusters,
        public readonly int $okClusters,
        public readonly int $pendingClusters,
        public readonly int $unavailableClusters,
        public readonly ?float $median,
        public readonly ?float $p25,
        public readonly ?float $p75,
        public readonly int $betterClusters,
        public readonly int $recordedTrades,
        public readonly array $tradeIds,
    ) {}
}
