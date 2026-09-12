<?php

namespace App\Services\Import\Support;

/**
 * Result of parsing one uploaded 楽天証券「お気に入り銘柄リスト」CSV
 * (F-012 / UC-012): the watchlist rows that parsed successfully, plus a
 * count of rows skipped because their 市場区分 is out of scope (CFD 等) or
 * their structure was invalid. Mirrors App\Services\Import\Support\ParsedCsvFile.
 */
final class ParsedFavoriteFile
{
    /**
     * @param  array<int, ParsedFavoriteRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $skippedCount,
    ) {}
}
