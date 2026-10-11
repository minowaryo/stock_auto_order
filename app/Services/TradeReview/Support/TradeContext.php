<?php

namespace App\Services\TradeReview\Support;

/**
 * What the system knew about a holding just before a trade (UC-018 基本
 * フロー7): the signals and indicators recorded in the 8 days before the
 * trade day began, and whether it was held the week before. Shown as the
 * observed situation, never as the reason for the trade.
 */
final class TradeContext
{
    /**
     * @param  list<array{source: string, signal_type: string, observed_week: string}>  $signals
     * @param  array<string, mixed>|null  $metrics
     */
    public function __construct(
        public readonly int $tradeId,
        public readonly array $signals,
        public readonly ?array $metrics,
        public readonly ?string $observedAt,
        public readonly bool $known,
        public readonly bool $heldBefore,
        public readonly float $weightBefore,
    ) {}
}
