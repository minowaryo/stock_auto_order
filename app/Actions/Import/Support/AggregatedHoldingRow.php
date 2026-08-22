<?php

namespace App\Actions\Import\Support;

/**
 * One (symbol_code, market) holding after aggregating across every parsed
 * CSV row for that symbol (multiple 楽天証券 account sections merged into a
 * single quantity + weighted-average cost, per UC-001業務ルール).
 */
final class AggregatedHoldingRow
{
    /**
     * Per (symbol_code, market, account_type) breakdown, used to populate
     * `holding_snapshot_accounts` (docs/adr/ADR-0002-nisa-account-type-tracking.md).
     *
     * @param  array<int, array{accountType: string, quantity: float, averageCost: float}>  $accountBreakdown
     */
    public function __construct(
        public readonly string $symbolCode,
        public readonly string $symbolName,
        public readonly string $market,
        public readonly string $instrumentType,
        public readonly float $quantity,
        public readonly float $averageCost,
        public readonly float $currentPrice,
        public readonly ?float $fxRateUsed,
        public readonly array $accountBreakdown,
    ) {}
}
