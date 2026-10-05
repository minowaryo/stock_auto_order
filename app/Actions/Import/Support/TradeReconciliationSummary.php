<?php

namespace App\Actions\Import\Support;

/**
 * Per-status counts of one trade history reconciliation (UC-017 出力).
 */
final class TradeReconciliationSummary
{
    /**
     * @param  array{matched: int, snapshot_only: int, history_only: int, needs_review: int}  $counts
     */
    public function __construct(
        public readonly int $snapshotId,
        public readonly array $counts,
    ) {}
}
