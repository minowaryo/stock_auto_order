<?php

namespace App\Services\TradeReview\Support;

/**
 * One trade compared with its alternative, at one horizon (UC-018 基本フロー
 * 4・5・6): for a sell / switch the alternative is holding on, for a buy it is
 * buying more of the existing holdings in proportion. diffJpy = actualJpy −
 * holdJpy: > 0 means the trade did better. A buy also carries the index
 * comparison (reference only).
 */
final class TradeComparison
{
    /**
     * @param  'sell'|'switch'|'buy'  $type
     * @param  4|13|26  $horizon  weeks after the trade's week
     * @param  'ok'|'pending'|'unavailable'  $status
     * @param  'no_price'|'split_pending'|'split_incomplete'|'no_holdings'|null  $reason
     */
    public function __construct(
        public readonly int $tradeId,
        public readonly string $type,
        public readonly int $horizon,
        public readonly string $evaluationWeek,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly float $baseJpy,
        public readonly ?float $actualJpy,
        public readonly ?float $holdJpy,
        public readonly ?float $diffJpy,
        public readonly ?float $diffRate,
        public readonly ?float $indexJpy = null,
        public readonly ?float $diffIndexJpy = null,
    ) {}
}
