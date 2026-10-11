<?php

namespace App\Services\TradeReview\Support;

/**
 * One trade compared with holding on, at one horizon (UC-018 基本フロー4・5).
 * diffJpy = actualJpy − holdJpy: > 0 means the trade did better.
 */
final class TradeComparison
{
    /**
     * @param  'sell'|'switch'  $type
     * @param  4|13|26  $horizon  weeks after the trade's week
     * @param  'ok'|'pending'|'unavailable'  $status
     * @param  'no_price'|'split_pending'|'split_incomplete'|null  $reason
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
    ) {}
}
