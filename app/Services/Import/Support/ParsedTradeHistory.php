<?php

namespace App\Services\Import\Support;

/**
 * Result of parsing one trade history CSV: the rows that parsed
 * successfully, plus a count of rows skipped because they could not be
 * parsed (UC-017 異常時).
 */
final class ParsedTradeHistory
{
    /**
     * @param  array<int, ParsedTradeRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $errorCount,
    ) {}
}
