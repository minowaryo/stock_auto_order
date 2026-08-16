<?php

namespace App\Services\Import\Support;

/**
 * Result of parsing one uploaded CSV file: the rows that parsed
 * successfully, plus a count of individual rows that were skipped because
 * they could not be parsed (UC-001業務ルール).
 */
final class ParsedCsvFile
{
    /**
     * @param  array<int, ParsedCsvRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $errorCount,
    ) {}
}
